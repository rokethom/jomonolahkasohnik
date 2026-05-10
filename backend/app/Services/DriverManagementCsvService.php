<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        'password',
        'branch_id',
        'branch_name',
        'vehicle_type',
        'vehicle_types',
        'vehicle_seat_rows',
        'is_ladies_driver',
        'can_accept_all_areas',
        'allowed_service_types',
        'bansos_amount',
        'bpjs_jht_enabled',
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
                            '',
                            $user->branch_id,
                            $user->branch?->display_name,
                            $driver?->vehicle_type ?: 'motor',
                            implode('|', $driver?->vehicleTypes() ?? ['motor']),
                            $driver?->vehicle_seat_rows ?: ($driver?->vehicle_type === 'mobil' ? 2 : ''),
                            $driver?->is_ladies_driver ? 'yes' : 'no',
                            $driver?->can_accept_all_areas ? 'yes' : 'no',
                            implode('|', $driver?->allowed_service_types ?? []),
                            $driver?->bansos_amount,
                            $driver?->bpjs_jht_enabled ? 'yes' : 'no',
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
     * @return array{created: int, updated: int, skipped: int, errors: array<int, string>, credentials: array<int, array{username: string, email: string, password: string}>}
     */
    public function importCsv(string $path, bool $allowCreate = false): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['File import tidak bisa dibaca.'], 'credentials' => []];
        }

        try {
            $headers = $this->readHeaders($handle);
        } catch (\Throwable $exception) {
            fclose($handle);

            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()], 'credentials' => []];
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $credentials = [];
        $rowNumber = 1;

        while (($row = fgetcsv($handle, 0, self::DELIMITER)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $data = $this->combineRow($headers, $row);

            try {
                $result = $this->upsertDriverRow($data, $allowCreate);
                if ($result['created']) {
                    $created++;
                    if ($result['credential'] !== null) {
                        $credentials[] = $result['credential'];
                    }
                } else {
                    $updated++;
                }
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = "Baris {$rowNumber}: ".$exception->getMessage();
            }
        }

        fclose($handle);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
            'credentials' => array_slice($credentials, 0, 50),
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
        $missingHeaders = array_diff(['name', 'username', 'email_google'], $headers);

        if ($missingHeaders !== []) {
            throw new \RuntimeException('Header CSV tidak sesuai. Minimal wajib ada name, username, dan email_google. Lebih aman download ulang template dari tombol Export CSV.');
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
    private function upsertDriverRow(array $data, bool $allowCreate): array
    {
        $user = $this->findDriverUser($data, false);

        if (! $user && ! $allowCreate) {
            throw new \RuntimeException('Driver tidak ditemukan. Aktifkan opsi buat driver baru jika ingin create massal.');
        }

        $email = strtolower($data['email_google'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('email_google tidak valid.');
        }

        if (! $user) {
            return $this->createDriverRow($data, $email);
        }

        if (! $user->driver) {
            throw new \RuntimeException('Akun driver tidak memiliki record driver.');
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
                'vehicle_type' => $this->normalizeVehicleTypes($data)[0] ?? 'motor',
                'vehicle_types' => $this->normalizeVehicleTypes($data),
                'vehicle_seat_rows' => in_array('mobil', $this->normalizeVehicleTypes($data), true) ? $this->normalizeSeatRows($data['vehicle_seat_rows'] ?? null) : null,
                'is_ladies_driver' => $this->booleanValue($data['is_ladies_driver'] ?? 'no'),
                'can_accept_all_areas' => $this->booleanValue($data['can_accept_all_areas'] ?? 'no'),
                'allowed_service_types' => $this->normalizeServiceTypes($data['allowed_service_types'] ?? ''),
                'bansos_amount' => $this->nullableInteger($data['bansos_amount'] ?? null),
                'bpjs_jht_enabled' => $this->booleanValue($data['bpjs_jht_enabled'] ?? 'yes'),
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

        return ['created' => false, 'credential' => null];
    }

    /**
     * @param  array<string, string>  $data
     * @return array{created: bool, credential: array{username: string, email: string, password: string}}
     */
    private function createDriverRow(array $data, string $email): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException('name wajib diisi untuk driver baru.');
        }

        if ($username === '') {
            $username = $this->uniqueUsername(Str::of($email)->before('@')->slug('_')->toString() ?: $name);
        }

        $this->ensureUniqueUserValue('email', $email, 0);
        $this->ensureUniqueUserValue('username', $username, 0);
        if (Schema::hasColumn('drivers', 'email')) {
            $this->ensureUniqueDriverEmail($email, 0);
        }

        $password = trim((string) ($data['password'] ?? ''));
        if ($password === '') {
            $password = Str::password(12);
        }

        DB::transaction(function () use ($data, $email, $username, $name, $password): void {
            $user = User::query()->create([
                'name' => $name,
                'username' => $username,
                'phone' => $data['phone'] ?: null,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => UserRole::Driver->value,
                'branch_id' => $this->resolveBranchId($data),
                'is_active' => true,
                'is_suspended' => false,
            ]);

            $vehicleTypes = $this->normalizeVehicleTypes($data);
            $driverPayload = [
                'name' => $name,
                'email' => Schema::hasColumn('drivers', 'email') ? $email : null,
                'vehicle_type' => $vehicleTypes[0] ?? 'motor',
                'vehicle_types' => $vehicleTypes,
                'vehicle_seat_rows' => in_array('mobil', $vehicleTypes, true) ? $this->normalizeSeatRows($data['vehicle_seat_rows'] ?? null) : null,
                'is_ladies_driver' => $this->booleanValue($data['is_ladies_driver'] ?? 'no'),
                'can_accept_all_areas' => $this->booleanValue($data['can_accept_all_areas'] ?? 'no'),
                'allowed_service_types' => $this->normalizeServiceTypes($data['allowed_service_types'] ?? ''),
                'bansos_amount' => $this->nullableInteger($data['bansos_amount'] ?? null),
                'bpjs_jht_enabled' => $this->booleanValue($data['bpjs_jht_enabled'] ?? 'yes'),
                'status' => $this->normalizeStatus($data['status'] ?? 'active'),
                'is_available' => $this->booleanValue($data['is_available'] ?? 'yes'),
                'is_suspend' => $this->booleanValue($data['is_suspend'] ?? 'no'),
            ];

            if (! Schema::hasColumn('drivers', 'email')) {
                unset($driverPayload['email']);
            }

            $user->driver()->create($driverPayload);

            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => 'filament_imported_driver_management_csv_created_driver',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'subject_label' => $user->email,
                'metadata' => [
                    'username' => $username,
                    'email' => $email,
                    'branch_id' => $user->branch_id,
                    'driver' => $driverPayload,
                ],
            ]);
        });

        return [
            'created' => true,
            'credential' => [
                'username' => $username,
                'email' => $email,
                'password' => $password,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    private function findDriverUser(array $data, bool $throw = true): ?User
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

        if (! $user && $throw) {
            throw new \RuntimeException('Driver tidak ditemukan. Pastikan user_id atau driver_id benar.');
        }

        return $user;
    }

    private function ensureUniqueUserValue(string $column, string $value, int $ignoreUserId): void
    {
        $query = User::query()->where($column, $value);
        if ($ignoreUserId > 0) {
            $query->where('id', '!=', $ignoreUserId);
        }

        $exists = $query->exists();

        if ($exists) {
            throw new \RuntimeException("{$column} sudah dipakai user lain.");
        }
    }

    private function ensureUniqueDriverEmail(string $email, int $ignoreDriverId): void
    {
        $query = Driver::query()->where('email', $email);
        if ($ignoreDriverId > 0) {
            $query->where('id', '!=', $ignoreDriverId);
        }

        $exists = $query->exists();

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
                ->orWhereRaw("CONCAT(area, ' - ', name) = ?", [$data['branch_name']])
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

    /**
     * @param  array<string, string>  $data
     * @return array<int, string>
     */
    private function normalizeVehicleTypes(array $data): array
    {
        $raw = filled($data['vehicle_types'] ?? null) ? $data['vehicle_types'] : ($data['vehicle_type'] ?? 'motor');

        $types = collect(explode('|', str_replace(',', '|', strtolower((string) $raw))))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => in_array($item, ['motor', 'mobil'], true))
            ->unique()
            ->values()
            ->all();

        return $types !== [] ? $types : ['motor'];
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

    private function nullableInteger(?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return max(0, (int) preg_replace('/[^\d]/', '', $value));
    }

    private function uniqueUsername(string $base): string
    {
        $base = Str::of($base ?: 'driver')->slug('_')->limit(40, '')->toString() ?: 'driver';
        $username = $base;
        $index = 1;

        while (User::query()->where('username', $username)->exists()) {
            $username = $base.'_'.$index;
            $index++;
        }

        return $username;
    }
}
