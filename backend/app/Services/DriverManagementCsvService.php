<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverManagementCsvService
{
    private const DELIMITER = ';';

    /**
     * @var array<int, string>
     */
    private const HEADERS = [
        'user_id',
        'driver_id',
        'name',
        'username',
        'phone',
        'email_google',
        'branch_id',
        'branch_name',
        'vehicle_type',
        'vehicle_seat_rows',
        'is_ladies_driver',
        'allowed_service_types',
        'status',
        'is_available',
        'is_suspend',
        'auth_suspended',
        'reset_google_bind',
        'revoke_tokens',
    ];

    public function downloadCsv(): StreamedResponse
    {
        $filename = 'driver-management-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::HEADERS, self::DELIMITER);

            User::query()
                ->with(['branch', 'driver'])
                ->where('role', UserRole::Driver->value)
                ->orderBy('name')
                ->chunk(200, function ($drivers) use ($handle): void {
                    foreach ($drivers as $user) {
                        $driver = $user->driver;

                        fputcsv($handle, [
                            $user->id,
                            $driver?->id,
                            $user->name,
                            $user->username,
                            $user->phone,
                            $this->driverEmail($user),
                            $user->branch_id,
                            $user->branch?->display_name,
                            $driver?->vehicle_type ?: 'motor',
                            $driver?->vehicle_seat_rows ?: ($driver?->vehicle_type === 'mobil' ? 2 : ''),
                            $driver?->is_ladies_driver ? 'yes' : 'no',
                            implode('|', $driver?->allowed_service_types ?? []),
                            $driver?->status ?: 'active',
                            $driver?->is_available ? 'yes' : 'no',
                            $driver?->is_suspend ? 'yes' : 'no',
                            $driver?->auth_suspended_at ? 'yes' : 'no',
                            'no',
                            'no',
                        ], self::DELIMITER);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{updated: int, skipped: int, errors: array<int, string>}
     */
    public function importCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['updated' => 0, 'skipped' => 0, 'errors' => ['File import tidak bisa dibaca.']];
        }

        try {
            $headers = $this->readHeaders($handle);
        } catch (\Throwable $exception) {
            fclose($handle);

            return ['updated' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()]];
        }

        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, self::DELIMITER)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $data = $this->combineRow($headers, $row);

            try {
                $this->updateDriverRow($data);
                $updated++;
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = "Baris {$rowNumber}: ".$exception->getMessage();
            }
        }

        fclose($handle);

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
        ];
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>
     */
    private function readHeaders($handle): array
    {
        $headers = fgetcsv($handle, 0, self::DELIMITER);

        if (! is_array($headers)) {
            throw new \RuntimeException('Header CSV tidak ditemukan.');
        }

        $headers = array_map(fn (string $header): string => trim(str_replace("\xEF\xBB\xBF", '', $header)), $headers);
        $missingHeaders = array_diff(['user_id', 'driver_id', 'email_google'], $headers);

        if ($missingHeaders !== []) {
            throw new \RuntimeException('Header CSV tidak sesuai. Export ulang file dari tombol Export CSV.');
        }

        return $headers;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string|null>  $row
     * @return array<string, string>
     */
    private function combineRow(array $headers, array $row): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            $data[$header] = trim((string) ($row[$index] ?? ''));
        }

        return $data;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        return collect($row)->every(fn ($value): bool => blank($value));
    }

    /**
     * @param  array<string, string>  $data
     */
    private function updateDriverRow(array $data): void
    {
        $user = $this->findDriverUser($data);

        if (! $user->driver) {
            throw new \RuntimeException('Akun driver tidak memiliki record driver.');
        }

        $email = strtolower($data['email_google'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('email_google tidak valid.');
        }

        $this->ensureUniqueUserValue('email', $email, $user->id);
        if (Schema::hasColumn('drivers', 'email')) {
            $this->ensureUniqueDriverEmail($email, (int) $user->driver->id);
        }

        if (filled($data['username'] ?? null)) {
            $this->ensureUniqueUserValue('username', $data['username'], $user->id);
        }

        DB::transaction(function () use ($user, $data, $email): void {
            $driver = $user->driver;
            $old = [
                'name' => $user->name,
                'username' => $user->username,
                'phone' => $user->phone,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
                'driver_email' => $this->driverEmail($user),
                'vehicle_type' => $driver->vehicle_type,
                'vehicle_seat_rows' => $driver->vehicle_seat_rows,
                'is_ladies_driver' => $driver->is_ladies_driver,
                'allowed_service_types' => $driver->allowed_service_types,
                'status' => $driver->status,
                'is_available' => $driver->is_available,
                'is_suspend' => $driver->is_suspend,
                'auth_suspended_at' => $driver->auth_suspended_at,
                'google_id' => $driver->google_id,
            ];

            $user->forceFill([
                'name' => $data['name'] ?: $user->name,
                'username' => $data['username'] ?: $user->username,
                'phone' => $data['phone'] ?: null,
                'email' => $email,
                'branch_id' => $this->resolveBranchId($data),
            ])->save();

            $driverUpdates = [
                'name' => $data['name'] ?: $user->name,
                'vehicle_type' => $this->normalizeVehicleType($data['vehicle_type'] ?? null),
                'vehicle_seat_rows' => $this->normalizeVehicleType($data['vehicle_type'] ?? null) === 'mobil' ? $this->normalizeSeatRows($data['vehicle_seat_rows'] ?? null) : null,
                'is_ladies_driver' => $this->booleanValue($data['is_ladies_driver'] ?? 'no'),
                'allowed_service_types' => $this->normalizeServiceTypes($data['allowed_service_types'] ?? ''),
                'status' => $this->normalizeStatus($data['status'] ?? null),
                'is_available' => $this->booleanValue($data['is_available'] ?? 'yes'),
                'is_suspend' => $this->booleanValue($data['is_suspend'] ?? 'no'),
                'auth_suspended_at' => $this->booleanValue($data['auth_suspended'] ?? 'no') ? ($driver->auth_suspended_at ?? now()) : null,
            ];

            if (Schema::hasColumn('drivers', 'email')) {
                $driverUpdates['email'] = $email;
            }

            if ($this->booleanValue($data['reset_google_bind'] ?? 'no')) {
                $driverUpdates['google_id'] = null;
                $driverUpdates['auth_failed_attempts'] = 0;
                $driverUpdates['auth_locked_until'] = null;
            }

            $driver->forceFill($driverUpdates)->save();

            if ($this->booleanValue($data['revoke_tokens'] ?? 'no') || array_key_exists('google_id', $driverUpdates)) {
                $user->tokens()->delete();
            }

            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => 'filament_imported_driver_management_csv',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'subject_label' => $user->email,
                'metadata' => [
                    'old' => $old,
                    'new' => [
                        'name' => $user->name,
                        'username' => $user->username,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'branch_id' => $user->branch_id,
                        ...$driverUpdates,
                    ],
                ],
            ]);
        });
    }

    /**
     * @param  array<string, string>  $data
     */
    private function findDriverUser(array $data): User
    {
        $query = User::query()
            ->with('driver')
            ->where('role', UserRole::Driver->value);

        if (filled($data['user_id'] ?? null)) {
            $user = (clone $query)->whereKey((int) $data['user_id'])->first();
        } elseif (filled($data['driver_id'] ?? null)) {
            $user = (clone $query)->whereHas('driver', fn ($query) => $query->whereKey((int) $data['driver_id']))->first();
        } elseif (filled($data['email_google'] ?? null)) {
            $email = strtolower($data['email_google']);
            $user = (clone $query)->where(function ($query) use ($email): void {
                $query->where('email', $email);

                if (Schema::hasColumn('drivers', 'email')) {
                    $query->orWhereHas('driver', fn ($query) => $query->where('email', $email));
                }
            })->first();
        } else {
            $user = null;
        }

        if (! $user) {
            throw new \RuntimeException('Driver tidak ditemukan. Pastikan user_id atau driver_id benar.');
        }

        return $user;
    }

    private function ensureUniqueUserValue(string $column, string $value, int $ignoreUserId): void
    {
        $exists = User::query()
            ->where($column, $value)
            ->where('id', '!=', $ignoreUserId)
            ->exists();

        if ($exists) {
            throw new \RuntimeException("{$column} sudah dipakai user lain.");
        }
    }

    private function ensureUniqueDriverEmail(string $email, int $ignoreDriverId): void
    {
        $exists = Driver::query()
            ->where('email', $email)
            ->where('id', '!=', $ignoreDriverId)
            ->exists();

        if ($exists) {
            throw new \RuntimeException('email_google sudah dipakai driver lain.');
        }
    }

    private function driverEmail(User $user): string
    {
        if (Schema::hasColumn('drivers', 'email') && filled($user->driver?->email)) {
            return (string) $user->driver->email;
        }

        return (string) $user->email;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function resolveBranchId(array $data): ?int
    {
        if (filled($data['branch_id'] ?? null)) {
            $branchId = (int) $data['branch_id'];

            if (! Branch::query()->whereKey($branchId)->exists()) {
                throw new \RuntimeException('branch_id tidak ditemukan.');
            }

            return $branchId;
        }

        if (filled($data['branch_name'] ?? null)) {
            $branch = Branch::query()
                ->where('name', $data['branch_name'])
                ->orWhere('area', $data['branch_name'])
                ->first();

            return $branch?->id;
        }

        return null;
    }

    private function normalizeVehicleType(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['motor', 'mobil'], true) ? $value : 'motor';
    }

    private function normalizeSeatRows(?string $value): int
    {
        $rows = (int) trim((string) $value);

        return in_array($rows, [2, 3], true) ? $rows : 2;
    }

    private function normalizeStatus(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['active', 'inactive', 'suspended', 'suspended_unpaid'], true) ? $value : 'active';
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeServiceTypes(string $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        return collect(explode('|', str_replace(',', '|', $value)))
            ->map(fn (string $item): string => strtolower(trim($item)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function booleanValue(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'yes', 'y', 'true', 'iya', 'ya', 'aktif', 'active'], true);
    }
}
