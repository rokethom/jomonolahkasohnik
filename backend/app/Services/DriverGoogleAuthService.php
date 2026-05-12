<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\UserRole;
use App\Exceptions\DriverGoogleLoginException;
use App\Models\AuditLog;
use App\Models\Driver;
use Google\Client as GoogleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DriverGoogleAuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(
        private readonly SettingService $settings,
    ) {
    }

    public function login(string $idToken, Request $request): array
    {
        $payload = $this->verifyGoogleToken($idToken);
        $email = strtolower((string) ($payload['email'] ?? ''));
        $googleId = (string) ($payload['sub'] ?? '');

        if ($email === '' || $googleId === '') {
            throw new DriverGoogleLoginException('Token Google tidak valid', 401, 'missing_google_identity');
        }

        /** @var Driver|null $matchedDriver */
        $matchedDriver = Driver::query()
            ->with('user')
            ->where(function ($query) use ($email): void {
                if (Schema::hasColumn('drivers', 'email')) {
                    $query->whereRaw('LOWER(email) = ?', [$email])
                        ->orWhereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]));

                    return;
                }

                $query->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]));
            })
            ->first();

        if (! $matchedDriver || ! $matchedDriver->user) {
            $this->logSecurityEvent('driver_google_login_unregistered_email', null, [
                'email' => $this->maskEmail($email),
                'ip' => $request->ip(),
            ]);

            throw new DriverGoogleLoginException('Email tidak terdaftar sebagai driver', 403, 'email_not_registered');
        }

        $this->assertDriverMayLogin($matchedDriver, $googleId, $request);

        return DB::transaction(function () use ($matchedDriver, $googleId, $request): array {
            /** @var Driver $driver */
            $driver = Driver::query()->with('user')->lockForUpdate()->findOrFail($matchedDriver->id);

            if ($driver->isAuthLocked()) {
                throw new DriverGoogleLoginException('Login driver terkunci sementara. Coba lagi nanti.', 423, 'driver_auth_locked');
            }

            if ($driver->google_id && ! hash_equals($driver->google_id, $googleId)) {
                $this->registerFailedAttempt($driver, 'google_id_mismatch', $request);
                throw new DriverGoogleLoginException('Akun Google tidak sesuai dengan driver ini', 403, 'google_id_mismatch');
            }

            if (! $driver->user?->is_active || $driver->user?->is_suspended || $driver->isLoginSuspended()) {
                $this->registerFailedAttempt($driver, 'driver_suspended_or_inactive', $request);
                throw new DriverGoogleLoginException('Driver nonaktif atau sedang suspend', 403, 'driver_suspended_or_inactive');
            }

            if (! $driver->google_id) {
                $driver->forceFill(['google_id' => $googleId])->save();
            }

            $updates = [
                'name' => $driver->name ?: $driver->user?->name,
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
                'last_login_device' => trim((string) $request->input('device_name', 'Driver App')) ?: null,
                'auth_failed_attempts' => 0,
                'auth_locked_until' => null,
            ];

            if (Schema::hasColumn('drivers', 'email')) {
                $updates['email'] = $driver->email ?: $driver->user?->email;
            }

            $driver->forceFill($updates)->save();

            $user = $driver->user;
            $user->tokens()->delete();

            $deviceName = trim((string) $request->input('device_name', 'Driver App'));
            $token = $user->createToken('driver-google:'.substr($deviceName ?: 'device', 0, 80))->plainTextToken;

            $this->logSecurityEvent('driver_google_login_success', $driver, [
                'ip' => $request->ip(),
                'device_name' => $deviceName,
            ]);

            Log::info('driver_google_login.success', [
                'driver_id' => $driver->id,
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return [
                'token' => $token,
                'driver' => $driver->fresh(['user.branch']),
            ];
        });
    }

    private function verifyGoogleToken(string $idToken): array
    {
        $clientIds = $this->googleClientIds();

        if ($clientIds === []) {
            throw new DriverGoogleLoginException('Google Sign-In belum dikonfigurasi', 503, 'missing_google_client_id');
        }

        foreach ($clientIds as $clientId) {
            try {
                $client = new GoogleClient(['client_id' => $clientId]);
                $payload = $client->verifyIdToken($idToken);
            } catch (Throwable $exception) {
                Log::warning('driver_google_login.token_verify_failed', [
                    'client_id_suffix' => substr($clientId, -8),
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if (is_array($payload) && $this->isValidPayload($payload, $clientId)) {
                return $payload;
            }
        }

        throw new DriverGoogleLoginException('Token Google tidak valid', 401, 'invalid_google_token');
    }

    private function isValidPayload(array $payload, string $clientId): bool
    {
        $audience = $payload['aud'] ?? null;
        $issuer = $payload['iss'] ?? null;
        $expiresAt = (int) ($payload['exp'] ?? 0);

        return hash_equals($clientId, (string) $audience)
            && in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            && $expiresAt > now()->timestamp;
    }

    private function assertDriverMayLogin(Driver $driver, string $googleId, Request $request): void
    {
        $user = $driver->user;
        $role = $user->role instanceof UserRole ? $user->role->value : (string) $user->role;

        if ($role !== UserRole::Driver->value) {
            $this->denyDriverLogin($driver, 'Akun ini bukan driver.', 'role_not_driver', $request);
        }

        if ($driver->isAuthLocked()) {
            throw new DriverGoogleLoginException('Login driver terkunci sementara. Coba lagi nanti.', 423, 'driver_auth_locked');
        }
    }

    private function denyDriverLogin(Driver $driver, string $message, string $reason, Request $request): void
    {
        $this->logSecurityEvent('driver_google_login_denied', $driver, [
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        throw new DriverGoogleLoginException($message, 403, $reason);
    }

    private function registerFailedAttempt(Driver $driver, string $reason, Request $request): void
    {
        $attempts = min(255, ((int) $driver->auth_failed_attempts) + 1);
        $lockedUntil = $attempts >= self::MAX_FAILED_ATTEMPTS ? now()->addMinutes(self::LOCK_MINUTES) : $driver->auth_locked_until;

        $driver->forceFill([
            'auth_failed_attempts' => $attempts,
            'auth_locked_until' => $lockedUntil,
        ])->save();

        $this->logSecurityEvent('driver_google_login_failed_attempt', $driver, [
            'reason' => $reason,
            'attempts' => $attempts,
            'locked_until' => $lockedUntil?->toDateTimeString(),
            'ip' => $request->ip(),
        ]);
    }

    private function googleClientIds(): array
    {
        $raw = (string) (
            env('GOOGLE_DRIVER_CLIENT_IDS')
            ?: env('GOOGLE_DRIVER_CLIENT_ID')
            ?: $this->settings->get('google_oauth_client_id')
            ?: config('services.google.client_id')
        );

        return collect(explode(',', $raw))
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function logSecurityEvent(string $action, ?Driver $driver, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => $driver?->user_id,
            'action' => $action,
            'subject_type' => Driver::class,
            'subject_id' => $driver?->id,
            'subject_label' => $driver?->user?->email,
            'metadata' => $metadata,
        ]);
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return 'invalid-email';
        }

        [$name, $domain] = explode('@', $email, 2);

        return str($name)->limit(2, '***').'@'.$domain;
    }
}
