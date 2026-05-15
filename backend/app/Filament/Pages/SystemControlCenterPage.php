<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\SettingService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

class SystemControlCenterPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System Control Center';

    protected static ?string $title = 'System Control Center';

    protected static ?string $slug = 'system-control-center';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.system-control-center-page';

    public string $whitelistIps = '';

    /** @var array<string, mixed> */
    public array $health = [];

    /** @var array<int, array<string, mixed>> */
    public array $staffUsers = [];

    /** @var array<int, array<string, mixed>> */
    public array $failedLogins = [];

    /** @var array<int, array<string, mixed>> */
    public array $forensicLogs = [];

    /** @var array<int, array<string, mixed>> */
    public array $backups = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public function mount(SettingService $settings): void
    {
        $this->whitelistIps = implode("\n", $this->loadWhitelistIps($settings));
        $this->refreshDashboard();
    }

    public function refreshDashboard(): void
    {
        $this->summary = $this->summaryData();
        $this->health = $this->healthData();
        $this->staffUsers = $this->staffUserRows();
        $this->failedLogins = $this->auditRows(['failed_admin_login'], 10);
        $this->forensicLogs = $this->auditRows([
            'updated_order_price',
            'driver_suspended',
            'filament_suspended_driver_google_auth',
            'suspended_driver_google_auth',
            'deleted_order',
            'filament_deleted_order',
            'updated_driver_deposit_report',
            'marked_driver_deposit_paid',
            'marked_driver_deposit_unpaid',
            'reset_user_token',
            'system_control_backup_database',
            'system_control_restore_database',
            'system_control_cleanup_logs',
            'system_control_merge_duplicates',
        ], 18);
        $this->backups = $this->backupRows();
    }

    public function saveWhitelistIps(SettingService $settings): void
    {
        $ips = collect(preg_split('/\R+/', $this->whitelistIps) ?: [])
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $settings->set('backend_whitelist_ips', json_encode($ips, JSON_UNESCAPED_SLASHES), true, ['type' => 'json']);
        $this->recordAudit('system_control_saved_backend_ip_whitelist', null, ['ips' => $ips]);

        Notification::make()->title('Whitelist IP backend tersimpan')->success()->send();
        $this->refreshDashboard();
    }

    public function clearLaravelCache(): void
    {
        Artisan::call('optimize:clear');
        $this->recordAudit('system_control_clear_laravel_cache');

        Notification::make()->title('Cache Laravel dibersihkan')->body(trim(Artisan::output()) ?: 'optimize:clear selesai.')->success()->send();
        $this->refreshDashboard();
    }

    public function runStorageLink(): void
    {
        Artisan::call('storage:link');
        $this->recordAudit('system_control_storage_link');

        Notification::make()->title('Storage link dicek')->body(trim(Artisan::output()) ?: 'storage:link selesai.')->success()->send();
        $this->refreshDashboard();
    }

    public function restartSupervisor(): void
    {
        $result = $this->runProcess(['supervisorctl', 'restart', 'all'], 45);
        $this->recordAudit('system_control_restart_supervisor', null, ['result' => $result]);

        Notification::make()
            ->title($result['ok'] ? 'Supervisor direstart' : 'Supervisor gagal direstart')
            ->body($result['output'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();

        $this->refreshDashboard();
    }

    public function backupDatabase(): void
    {
        $backup = $this->createDatabaseBackup('manual-backup');
        $this->recordAudit('system_control_backup_database', null, $backup);

        Notification::make()
            ->title($backup['ok'] ? 'Backup database selesai' : 'Backup database gagal')
            ->body($backup['message'])
            ->{$backup['ok'] ? 'success' : 'danger'}()
            ->send();

        $this->refreshDashboard();
    }

    public function exportFullData(): void
    {
        $backup = $this->createDatabaseBackup('full-export');
        $this->recordAudit('system_control_export_full_data', null, $backup);

        Notification::make()
            ->title($backup['ok'] ? 'Export full data selesai' : 'Export full data gagal')
            ->body($backup['message'])
            ->{$backup['ok'] ? 'success' : 'danger'}()
            ->send();

        $this->refreshDashboard();
    }

    public function restoreDatabase(string $file): void
    {
        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.basename($file);
        if (! File::exists($path)) {
            Notification::make()->title('File backup tidak ditemukan')->danger()->send();
            return;
        }

        $result = $this->restoreDatabaseBackup($path);
        $this->recordAudit('system_control_restore_database', null, ['file' => basename($path), 'result' => $result]);

        Notification::make()
            ->title($result['ok'] ? 'Restore database selesai' : 'Restore database gagal')
            ->body($result['output'])
            ->{$result['ok'] ? 'success' : 'danger'}()
            ->send();

        $this->refreshDashboard();
    }

    public function cleanupOldLogs(): void
    {
        $deleted = 0;
        foreach (File::glob(storage_path('logs/*.log')) ?: [] as $file) {
            if (File::lastModified($file) < now()->subDays(14)->getTimestamp()) {
                File::delete($file);
                $deleted++;
            }
        }

        DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(30))->delete();
        $this->recordAudit('system_control_cleanup_logs', null, ['deleted_log_files' => $deleted]);

        Notification::make()->title('Cleanup log lama selesai')->body("{$deleted} file log lama dihapus.")->success()->send();
        $this->refreshDashboard();
    }

    public function migrateBranchAreaData(): void
    {
        $created = 0;
        Branch::query()->chunkById(100, function ($branches) use (&$created): void {
            foreach ($branches as $branch) {
                if ($branch->createPrimaryGeofenceAreaIfMissing()) {
                    $created++;
                }
            }
        });

        $this->recordAudit('system_control_migrate_branch_area_data', null, ['processed_branches' => Branch::query()->count(), 'geofence_checked' => $created]);
        Notification::make()->title('Migrasi/sinkron area cabang selesai')->body('Geofence utama cabang sudah dicek ulang.')->success()->send();
        $this->refreshDashboard();
    }

    public function mergeDuplicateCustomers(): void
    {
        $merged = 0;
        DB::transaction(function () use (&$merged): void {
            $groups = User::query()
                ->where('role', UserRole::Customer->value)
                ->whereNotNull('phone')
                ->select('phone', DB::raw('COUNT(*) as total'))
                ->groupBy('phone')
                ->having('total', '>', 1)
                ->limit(100)
                ->pluck('phone');

            foreach ($groups as $phone) {
                $users = User::query()->where('role', UserRole::Customer->value)->where('phone', $phone)->orderBy('id')->get();
                $primary = $users->shift();
                if (! $primary) {
                    continue;
                }

                foreach ($users as $duplicate) {
                    DB::table('orders')->where('user_id', $duplicate->id)->update(['user_id' => $primary->id]);
                    $duplicate->delete();
                    $merged++;
                }
            }
        });

        $this->recordAudit('system_control_merge_duplicates', null, ['type' => 'customer', 'merged' => $merged]);
        Notification::make()->title('Merge duplicate customer selesai')->body("{$merged} customer duplicate digabung.")->success()->send();
        $this->refreshDashboard();
    }

    public function lockStaff(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user || auth()->id() === $user->id || $user->role === UserRole::Admin) {
            Notification::make()->title('Akun ini tidak boleh dikunci dari sini')->danger()->send();
            return;
        }

        $user->forceFill([
            'is_suspended' => true,
            'suspension_reason' => 'Locked from System Control Center',
            'suspended_until' => null,
        ])->save();
        $user->tokens()->delete();
        $user->deviceTokens()->update(['is_active' => false]);

        $this->recordAudit('system_control_locked_staff', $user);
        Notification::make()->title('Akun staff dikunci')->body($user->name)->success()->send();
        $this->refreshDashboard();
    }

    public function unlockStaff(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user || $user->role === UserRole::Admin) {
            Notification::make()->title('Akun tidak ditemukan')->danger()->send();
            return;
        }

        $user->forceFill([
            'is_suspended' => false,
            'suspension_reason' => null,
            'suspended_until' => null,
        ])->save();

        $this->recordAudit('system_control_unlocked_staff', $user);
        Notification::make()->title('Akun staff dibuka')->body($user->name)->success()->send();
        $this->refreshDashboard();
    }

    public function resetUserTokens(int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user || auth()->id() === $user->id) {
            Notification::make()->title('Token akun ini tidak bisa direset dari sini')->danger()->send();
            return;
        }

        $user->tokens()->delete();
        $user->deviceTokens()->update(['is_active' => false]);

        $this->recordAudit('system_control_reset_user_tokens', $user);
        Notification::make()->title('Token user direset')->body($user->name.' harus login ulang.')->success()->send();
        $this->refreshDashboard();
    }

    public function revokeAllSessions(): void
    {
        User::query()->where('id', '!=', auth()->id())->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $user->tokens()->delete();
                $user->deviceTokens()->update(['is_active' => false]);
            }
        });

        $this->recordAudit('system_control_revoked_all_sessions');
        Notification::make()->title('Semua token device direvoke')->body('Semua user selain akun admin aktif harus login ulang.')->success()->send();
        $this->refreshDashboard();
    }

    public function exportAuditLogs(): StreamedResponse
    {
        $this->recordAudit('system_control_export_audit_logs');

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'time', 'actor', 'action', 'subject_type', 'subject_id', 'subject_label', 'metadata']);

            AuditLog::query()
                ->with('user:id,name,email,role')
                ->latest()
                ->limit(5000)
                ->cursor()
                ->each(function (AuditLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->id,
                        $log->created_at?->toDateTimeString(),
                        $log->user?->name,
                        $log->action,
                        $log->subject_type,
                        $log->subject_id,
                        $log->subject_label,
                        json_encode($log->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                });

            fclose($handle);
        }, 'audit-logs-'.now()->format('Ymd-His').'.csv');
    }

    private function summaryData(): array
    {
        return [
            'failed_login_today' => AuditLog::query()->where('action', 'failed_admin_login')->whereDate('created_at', today())->count(),
            'suspended_staff' => User::query()->where('is_staff', true)->where('is_suspended', true)->count(),
            'active_tokens' => Schema::hasTable('personal_access_tokens') ? DB::table('personal_access_tokens')->count() : 0,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'audit_logs' => AuditLog::query()->count(),
        ];
    }

    private function healthData(): array
    {
        return [
            'backend' => ['ok' => true, 'label' => 'Laravel boot OK', 'detail' => app()->environment().' / '.app()->version()],
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorageLink(),
            'osrm' => $this->checkOsrm(),
            'builds' => $this->frontendBuildVersions(),
        ];
    }

    private function staffUserRows(): array
    {
        return User::query()
            ->where('is_staff', true)
            ->where('role', '!=', UserRole::Admin->value)
            ->with('branch:id,branch_code,name,area')
            ->latest('updated_at')
            ->limit(24)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->label(),
                'branch' => $user->branch?->display_name ?? '-',
                'is_suspended' => (bool) $user->is_suspended,
                'tokens' => $user->tokens()->count(),
                'updated_at' => $user->updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function auditRows(array $actions, int $limit): array
    {
        return AuditLog::query()
            ->with('user:id,name,email,role')
            ->whereIn('action', $actions)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'actor' => $log->user?->name ?? 'System',
                'action' => $log->action,
                'subject' => $log->subject_label ?: class_basename((string) $log->subject_type).' #'.$log->subject_id,
                'ip' => data_get($log->metadata, 'ip'),
                'device' => Str::limit((string) data_get($log->metadata, 'user_agent', data_get($log->metadata, 'device', '-')), 80),
                'time' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');
            return ['ok' => true, 'label' => 'Database OK', 'detail' => config('database.default').' / '.config('database.connections.mysql.database')];
        } catch (Throwable $exception) {
            return ['ok' => false, 'label' => 'Database error', 'detail' => $exception->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection()->ping();
            Cache::put('system_control_center:redis_check', now()->toDateTimeString(), 60);
            return ['ok' => true, 'label' => 'Redis OK', 'detail' => is_string($pong) ? $pong : 'PING OK'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'label' => 'Redis error', 'detail' => $exception->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        return [
            'ok' => $failed === 0,
            'label' => $failed === 0 ? 'Queue OK' : 'Queue perlu dicek',
            'detail' => config('queue.default').' / failed jobs: '.$failed,
        ];
    }

    private function checkStorageLink(): array
    {
        $link = public_path('storage');
        return [
            'ok' => is_link($link) || File::exists($link),
            'label' => (is_link($link) || File::exists($link)) ? 'Storage link OK' : 'Storage link belum ada',
            'detail' => $link,
        ];
    }

    private function checkOsrm(): array
    {
        $url = (string) app(SettingService::class)->get('osrm_base_url', config('services.osrm.base_url', 'https://router.project-osrm.org'));
        if ($url === '') {
            return ['ok' => false, 'label' => 'OSRM belum diset', 'detail' => '-'];
        }

        try {
            $response = Http::timeout(4)->get(rtrim($url, '/').'/route/v1/driving/114.00550184,-7.70686204;114.0115655,-7.705408', [
                'overview' => 'false',
            ]);

            return [
                'ok' => $response->successful(),
                'label' => $response->successful() ? 'OSRM OK' : 'OSRM error',
                'detail' => $url.' / HTTP '.$response->status(),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'label' => 'OSRM error', 'detail' => $exception->getMessage()];
        }
    }

    private function frontendBuildVersions(): array
    {
        $paths = [
            'admin' => base_path('../apps/admin/public/version.json'),
            'customer' => base_path('../apps/customer/public/version.json'),
            'driver' => base_path('../apps/driver/public/version.json'),
        ];

        return collect($paths)
            ->mapWithKeys(function (string $path, string $app): array {
                if (! File::exists($path)) {
                    return [$app => ['ok' => false, 'label' => strtoupper($app), 'detail' => 'version.json belum ada']];
                }

                $json = json_decode((string) File::get($path), true);

                return [$app => [
                    'ok' => is_array($json),
                    'label' => strtoupper($app),
                    'detail' => is_array($json) ? (($json['sha'] ?? 'unknown').' / '.($json['built_at'] ?? '-')) : 'invalid json',
                ]];
            })
            ->all();
    }

    private function backupRows(): array
    {
        File::ensureDirectoryExists($this->backupDirectory());

        return collect(File::files($this->backupDirectory()))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.sql'))
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->take(10)
            ->map(fn ($file): array => [
                'name' => $file->getFilename(),
                'size' => $this->humanFileSize($file->getSize()),
                'updated_at' => date('Y-m-d H:i:s', $file->getMTime()),
            ])
            ->values()
            ->all();
    }

    private function createDatabaseBackup(string $prefix): array
    {
        File::ensureDirectoryExists($this->backupDirectory());

        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.$prefix.'-'.now()->format('Ymd-His').'.sql';
        $command = [
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--host='.config('database.connections.mysql.host'),
            '--port='.config('database.connections.mysql.port'),
            '--user='.config('database.connections.mysql.username'),
            '--password='.config('database.connections.mysql.password'),
            config('database.connections.mysql.database'),
        ];

        $process = new Process($command);
        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful()) {
            return ['ok' => false, 'message' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'mysqldump gagal.'];
        }

        File::put($path, $process->getOutput());

        return ['ok' => true, 'message' => basename($path).' dibuat.', 'file' => basename($path), 'size' => $this->humanFileSize(File::size($path))];
    }

    private function restoreDatabaseBackup(string $path): array
    {
        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
            escapeshellarg((string) config('database.connections.mysql.host')),
            escapeshellarg((string) config('database.connections.mysql.port')),
            escapeshellarg((string) config('database.connections.mysql.username')),
            escapeshellarg((string) config('database.connections.mysql.password')),
            escapeshellarg((string) config('database.connections.mysql.database')),
            escapeshellarg($path),
        );

        return $this->runProcess(['/bin/bash', '-lc', $command], 240);
    }

    private function runProcess(array $command, int $timeout): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout($timeout);
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'output' => trim($process->getOutput()."\n".$process->getErrorOutput()) ?: 'Command selesai tanpa output.',
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'output' => $exception->getMessage()];
        }
    }

    private function loadWhitelistIps(SettingService $settings): array
    {
        $raw = $settings->get('backend_whitelist_ips', '[]');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function backupDirectory(): string
    {
        return storage_path('app/backups/system-control');
    }

    private function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function recordAudit(string $action, ?User $subject = null, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? User::class : self::class,
            'subject_id' => $subject?->id,
            'subject_label' => $subject?->email ?? $subject?->name ?? 'System Control Center',
            'metadata' => [
                ...$metadata,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ]);
    }
}
