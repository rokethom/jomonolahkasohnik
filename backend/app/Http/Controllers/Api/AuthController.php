<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\LocationService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, LocationService $locations)
    {
        $payload = $request->validated();
        $user = User::create([
            ...$request->safe()->only(['username', 'name', 'email', 'phone', 'password']),
            'role' => UserRole::Customer,
        ]);

        $locations->updateRegistrationLocation(
            $user,
            (float) ($payload['lat'] ?? $payload['location_lat']),
            (float) ($payload['lng'] ?? $payload['location_lng']),
            isset($payload['location_accuracy']) ? (float) $payload['location_accuracy'] : null,
            $payload['gps_timestamp'] ?? now(),
        );

        $user->tokens()->delete();
        $token = $user->createToken($this->tokenName($request))->plainTextToken;

        return response()->json([
            'message' => 'Registered',
            'user' => $user->fresh(['branch', 'currentLocation']),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = Auth::user();

        $role = $user->role instanceof UserRole ? $user->role : UserRole::tryFrom((string) $user->role);
        if ($role === UserRole::Driver) {
            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'Driver wajib login menggunakan Google',
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken($this->tokenName($request))->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function redirectToGoogle(SettingService $settings)
    {
        abort_unless($this->googleOAuthIsReady($settings), 403, 'Google OAuth sedang tidak aktif.');

        $this->configureGoogleOAuth($settings);

        return Socialite::driver('google')->stateless()->redirect();
    }

    public function handleGoogleCallback(SettingService $settings)
    {
        abort_unless($this->googleOAuthIsReady($settings), 403, 'Google OAuth sedang tidak aktif.');

        $this->configureGoogleOAuth($settings);
        $frontendUrl = $this->frontendCallbackUrl();

        if (request()->filled('error')) {
            return $this->redirectGoogleLoginError($frontendUrl, 'Google membatalkan atau menolak proses login.');
        }

        if (! request()->filled('code')) {
            Log::warning('Google OAuth callback missing code', [
                'query' => request()->query(),
                'ip' => request()->ip(),
            ]);

            return $this->redirectGoogleLoginError($frontendUrl, 'Sesi Login Google tidak lengkap. Silakan ulangi dari tombol Login Google.');
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $exception) {
            Log::warning('Google OAuth callback failed', [
                'message' => $exception->getMessage(),
            ]);

            return $this->redirectGoogleLoginError($frontendUrl, 'Sesi Login Google sudah tidak valid. Silakan coba lagi.');
        }

        $email = $googleUser->getEmail();
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->role !== UserRole::Customer) {
            return $this->redirectGoogleLoginError($frontendUrl, 'Email ini terdaftar sebagai akun staff. Gunakan email customer lain.');
        }

        if ($user) {
            $user->forceFill([
                'name' => $user->name ?: ($googleUser->getName() ?: $googleUser->getNickname() ?: 'Customer Jojo'),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        } else {
            $user = User::query()->create([
                'email' => $email,
                'username' => $this->uniqueGoogleUsername($email, $googleUser->getNickname()),
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'Customer Jojo',
                'password' => Hash::make(str()->random(40)),
                'role' => UserRole::Customer,
                'email_verified_at' => now(),
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken($this->tokenName(request()))->plainTextToken;

        return redirect()->away($frontendUrl.'/#auth_token='.urlencode($token));
    }

    private function redirectGoogleLoginError(string $frontendUrl, string $message)
    {
        return redirect()->away($frontendUrl.'/#error='.urlencode($message));
    }

    private function frontendCallbackUrl(): string
    {
        $configured = rtrim((string) config('app.frontend_url', ''), '/');

        if ($configured !== '') {
            return $configured;
        }

        return request()->getHost() === 'localhost' || request()->getHost() === '127.0.0.1'
            ? 'http://localhost:5172'
            : 'https://app.aplikasijoker.my.id';
    }

    private function tokenName(Request $request): string
    {
        return 'api-token:'.substr(hash('sha256', (string) $request->userAgent()), 0, 12);
    }

    private function uniqueGoogleUsername(string $email, ?string $nickname): string
    {
        $base = str($nickname ?: str($email)->before('@')->value())
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->limit(32, '')
            ->value() ?: 'customer';

        $username = $base;
        $counter = 1;

        while (User::query()->where('username', $username)->exists()) {
            $username = $base.'_'.++$counter;
        }

        return $username;
    }

    private function googleOAuthIsReady(SettingService $settings): bool
    {
        return $settings->bool('google_oauth_enabled')
            && filled($settings->get('google_oauth_client_id'))
            && filled($settings->get('google_oauth_secret'));
    }

    private function configureGoogleOAuth(SettingService $settings): void
    {
        config([
            'services.google.client_id' => $settings->get('google_oauth_client_id'),
            'services.google.client_secret' => $settings->get('google_oauth_secret'),
            'services.google.redirect' => url('/api/auth/google/callback'),
        ]);
    }
}
