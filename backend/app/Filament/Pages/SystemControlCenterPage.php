<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\SettingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Throwable;

class SystemControlCenterPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System Control Center';

    protected static ?string $title = 'System Control Center';

    protected static ?string $slug = 'system-control-center';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.system-control-center-page';

    public string $whitelistIps = '';

    public ?array $brandingData = [];

    public string $securitySearch = '';

    public string $auditSearch = '';

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

    public bool $autoBackupEnabled = false;

    public string $autoBackupTime = '02:00';

    public int $autoBackupRetentionDays = 14;

    /** @var array<string, mixed>|null */
    public ?array $latestAutoBackup = null;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<int, array<string, mixed>> */
    public array $roleDocumentation = [];

    /** @var array<int, array<string, string>> */
    public array $systemDocumentation = [];

    /** @var array<string, string> */
    public array $apiDocumentationNote = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(SettingService $settings): void
    {
        $this->whitelistIps = implode("\n", $this->loadWhitelistIps($settings));
        $this->brandingForm->fill($this->brandingState($settings));
        $this->loadAutoBackupSettings($settings);
        $this->refreshDashboard();
    }

    protected function getForms(): array
    {
        return ['brandingForm'];
    }

    public function brandingForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Customer App')
                    ->description('Nama, logo, dan icon aplikasi customer. Nilai kosong kembali memakai default Joker.')
                    ->columns(3)
                    ->schema([
                        $this->brandingNameInput('branding_customer_app_name', 'Nama aplikasi customer', 'Joker'),
                        $this->brandingImageUpload('branding_customer_logo', 'Logo header / install', 'customer', 'Logo yang tampil pada header chat dan tombol install.'),
                        $this->brandingImageUpload('branding_customer_hero_image', 'Gambar home', 'customer', 'Gambar brand pada tampilan utama customer.'),
                        $this->brandingImageUpload('branding_customer_favicon', 'Icon browser', 'customer', 'Icon tab browser, disarankan PNG persegi.'),
                        $this->brandingImageUpload('branding_customer_apple_touch_icon', 'Apple touch icon', 'customer', 'Icon shortcut iPhone/iPad, disarankan PNG 180x180.'),
                        $this->brandingPwaUpload('branding_customer_pwa_icon_192', 'PWA icon 192x192', 'customer'),
                        $this->brandingPwaUpload('branding_customer_pwa_icon_512', 'PWA icon 512x512', 'customer'),
                    ]),
                Forms\Components\Section::make('Driver App')
                    ->description('Nama, logo, dan icon aplikasi driver. Default nama tetap Driver Joker.')
                    ->columns(3)
                    ->schema([
                        $this->brandingNameInput('branding_driver_app_name', 'Nama aplikasi driver', 'Driver Joker'),
                        $this->brandingImageUpload('branding_driver_logo', 'Logo dashboard / install', 'driver', 'Logo yang tampil pada dashboard driver dan tombol install.'),
                        $this->brandingImageUpload('branding_driver_favicon', 'Icon browser', 'driver', 'Icon tab browser, disarankan PNG persegi.'),
                        $this->brandingImageUpload('branding_driver_apple_touch_icon', 'Apple touch icon', 'driver', 'Icon shortcut iPhone/iPad, disarankan PNG 180x180.'),
                        $this->brandingPwaUpload('branding_driver_pwa_icon_192', 'PWA icon 192x192', 'driver'),
                        $this->brandingPwaUpload('branding_driver_pwa_icon_512', 'PWA icon 512x512', 'driver'),
                    ]),
                Forms\Components\Section::make('Admin App')
                    ->description('Nama, logo, dan icon frontend admin. Default nama tetap Admin Joker.')
                    ->columns(3)
                    ->schema([
                        $this->brandingNameInput('branding_admin_app_name', 'Nama frontend admin', 'Admin Joker'),
                        $this->brandingImageUpload('branding_admin_logo', 'Logo frontend admin', 'admin', 'Logo sidebar, halaman login, dan tombol install FE Admin.'),
                        $this->brandingImageUpload('branding_admin_favicon', 'Icon browser', 'admin', 'Icon tab browser FE Admin.'),
                        $this->brandingPwaUpload('branding_admin_pwa_icon_192', 'PWA icon 192x192', 'admin'),
                        $this->brandingPwaUpload('branding_admin_pwa_icon_512', 'PWA icon 512x512', 'admin'),
                    ]),
                Forms\Components\Section::make('Filament Backend')
                    ->description('Nama brand panel CMS backend. Default tetap Jojoapp.')
                    ->schema([
                        $this->brandingNameInput('branding_backend_app_name', 'Nama backend Filament', 'Jojoapp'),
                    ]),
            ])
            ->statePath('brandingData');
    }

    public function saveBranding(SettingService $settings): void
    {
        abort_unless(auth()->user()?->role === UserRole::Admin, 403);

        $data = $this->brandingForm->getState();

        $settings->set('branding_customer_app_name', trim((string) ($data['branding_customer_app_name'] ?? 'Joker')) ?: 'Joker');
        $settings->set('branding_driver_app_name', trim((string) ($data['branding_driver_app_name'] ?? 'Driver Joker')) ?: 'Driver Joker');
        $settings->set('branding_admin_app_name', trim((string) ($data['branding_admin_app_name'] ?? 'Admin Joker')) ?: 'Admin Joker');
        $settings->set('branding_backend_app_name', trim((string) ($data['branding_backend_app_name'] ?? 'Jojoapp')) ?: 'Jojoapp');

        foreach ([
            'branding_customer_logo',
            'branding_customer_hero_image',
            'branding_customer_favicon',
            'branding_customer_apple_touch_icon',
            'branding_customer_pwa_icon_192',
            'branding_customer_pwa_icon_512',
            'branding_driver_logo',
            'branding_driver_favicon',
            'branding_driver_apple_touch_icon',
            'branding_driver_pwa_icon_192',
            'branding_driver_pwa_icon_512',
            'branding_admin_logo',
            'branding_admin_favicon',
            'branding_admin_pwa_icon_192',
            'branding_admin_pwa_icon_512',
        ] as $key) {
            $settings->set($key, $this->normalizeUploadState($data[$key] ?? null));
        }

        $this->recordAudit('system_control_saved_branding', null, [
            'customer_name' => $settings->brandingAppName('customer'),
            'driver_name' => $settings->brandingAppName('driver'),
            'admin_name' => $settings->brandingAppName('admin'),
            'backend_name' => $settings->brandingBackendName(),
        ]);

        Notification::make()->title('Branding aplikasi tersimpan')->body('Nama dan asset frontend/PWA sudah diperbarui.')->success()->send();
        $this->brandingForm->fill($this->brandingState($settings));
    }

    public function refreshDashboard(): void
    {
        $this->summary = $this->summaryData();
        $this->health = $this->healthData();
        $this->roleDocumentation = $this->roleDocumentationRows();
        $this->systemDocumentation = $this->systemDocumentationRows();
        $this->apiDocumentationNote = $this->apiDocumentationNote();
        $this->refreshSecurityRows();
        $this->refreshAuditRows();
        $this->backups = $this->backupRows();
        $this->latestAutoBackup = app(DatabaseBackupService::class)->latestAutoBackup();
    }

    public function updatedSecuritySearch(): void
    {
        $this->refreshSecurityRows();
    }

    public function updatedAuditSearch(): void
    {
        $this->refreshAuditRows();
    }

    private function refreshSecurityRows(): void
    {
        $this->staffUsers = $this->staffUserRows();
    }

    private function refreshAuditRows(): void
    {
        $this->failedLogins = $this->auditRows(['failed_admin_login'], 25);
        $this->forensicLogs = $this->auditRows([
            'updated_order_price',
            'approved_live_price_review',
            'rejected_live_price_review',
            'driver_suspended',
            'filament_suspended_driver_google_auth',
            'suspended_driver_google_auth',
            'deleted_order',
            'filament_deleted_order',
            'updated_driver_deposit_report',
            'edited_driver_deposit_report_row',
            'marked_driver_deposit_paid',
            'marked_driver_deposit_unpaid',
            'reset_user_token',
            'system_control_locked_user',
            'system_control_unlocked_user',
            'system_control_reset_user_tokens',
            'system_control_backup_database',
            'system_control_auto_backup_database',
            'system_control_auto_backup_database_test',
            'system_control_saved_auto_backup_settings',
            'system_control_saved_branding',
            'system_control_restore_database',
            'system_control_cleanup_logs',
            'system_control_merge_duplicates',
        ], 50);
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

    public function backupAndDownloadDatabase(): ?BinaryFileResponse
    {
        $backup = $this->createDatabaseBackup('manual-download');
        $this->recordAudit('system_control_backup_download_database', null, $backup);

        if (! ($backup['ok'] ?? false) || empty($backup['file'])) {
            Notification::make()
                ->title('Backup database gagal')
                ->body($backup['message'] ?? 'File backup tidak berhasil dibuat.')
                ->danger()
                ->send();

            $this->refreshDashboard();

            return null;
        }

        $this->refreshDashboard();

        return $this->downloadDatabaseBackup((string) $backup['file']);
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

    public function saveAutoBackupSettings(SettingService $settings): void
    {
        $this->autoBackupTime = $this->normalizeBackupTime($this->autoBackupTime);
        $this->autoBackupRetentionDays = max(1, min(180, (int) $this->autoBackupRetentionDays));

        $settings->set('scc_auto_database_backup_enabled', $this->autoBackupEnabled ? 'true' : 'false', true);
        $settings->set('scc_auto_database_backup_time', $this->autoBackupTime, true);
        $settings->set('scc_auto_database_backup_retention_days', (string) $this->autoBackupRetentionDays, true);

        $this->recordAudit('system_control_saved_auto_backup_settings', null, [
            'enabled' => $this->autoBackupEnabled,
            'time' => $this->autoBackupTime,
            'retention_days' => $this->autoBackupRetentionDays,
        ]);

        Notification::make()
            ->title('Auto backup database tersimpan')
            ->body($this->autoBackupEnabled ? 'Scheduler akan membuat backup sesuai jam yang dipilih.' : 'Auto backup database dinonaktifkan.')
            ->success()
            ->send();

        $this->refreshDashboard();
    }

    public function runAutoBackupNow(): void
    {
        $backup = $this->createDatabaseBackup('auto-backup');
        $deleted = app(DatabaseBackupService::class)->cleanupAutoBackupsOlderThan($this->autoBackupRetentionDays);
        $this->recordAudit('system_control_auto_backup_database_test', null, [
            'result' => $backup,
            'deleted_old_auto_backups' => $deleted,
        ]);

        Notification::make()
            ->title($backup['ok'] ? 'Test auto backup selesai' : 'Test auto backup gagal')
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

    public function downloadDatabaseBackup(string $file): BinaryFileResponse
    {
        $filename = basename($file);
        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.$filename;

        abort_unless(File::exists($path) && str_ends_with($filename, '.sql'), 404);

        $this->recordAudit('system_control_download_database_backup', null, ['file' => $filename]);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/sql',
        ]);
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

        $this->recordAudit('system_control_locked_user', $user);
        Notification::make()->title('Akun dikunci')->body($user->name)->success()->send();
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

        $this->recordAudit('system_control_unlocked_user', $user);
        Notification::make()->title('Akun dibuka')->body($user->name)->success()->send();
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
            'suspended_staff' => User::query()
                ->where('role', '!=', UserRole::Admin->value)
                ->where('is_suspended', true)
                ->count(),
            'active_tokens' => Schema::hasTable('personal_access_tokens') ? DB::table('personal_access_tokens')->count() : 0,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'audit_logs' => AuditLog::query()->count(),
        ];
    }

    private function roleDocumentationRows(): array
    {
        return [
            [
                'role' => 'Admin',
                'level' => 'Superadmin aplikasi',
                'scope' => 'Global semua cabang, semua area, semua modul.',
                'can' => [
                    'Akses System Control Center, security, deployment, database tools, dan audit forensic.',
                    'Kelola user semua role, driver, customer, order, pricing, CMS, branch, area, report, dan sistem.',
                    'Melakukan aksi berisiko tinggi: backup/restore database, revoke token massal, clear cache, restart worker.',
                ],
                'cannot' => [
                    'Tidak ada batasan operasional normal. Aksi destructive tetap harus lewat konfirmasi.',
                ],
            ],
            [
                'role' => 'GM',
                'level' => 'Global operasional',
                'scope' => 'Global semua cabang dan area operasional, tanpa menu khusus superadmin.',
                'can' => [
                    'Melihat dan mengelola order, driver, user operasional, report, pricing, manual order, dan CMS operasional.',
                    'Membuat/mengubah role operasional sesuai assignable role.',
                    'Monitoring lintas cabang untuk keputusan operasional.',
                ],
                'cannot' => [
                    'Tidak boleh masuk System Control Center superadmin.',
                    'Tidak boleh menjalankan maintenance server/database dari Filament.',
                ],
            ],
            [
                'role' => 'HRD',
                'level' => 'Branch kota/kab',
                'scope' => 'Area di bawah branch yang diberikan, misalnya seluruh area di STB.',
                'can' => [
                    'Create, edit, dan melihat data user/driver dalam scope branch.',
                    'Melihat report sesuai izin.',
                    'Mengelola data driver operasional seperti status, setoran, dan konfigurasi yang diizinkan.',
                ],
                'cannot' => [
                    'Tidak bisa mengakses branch lain kecuali diberi global/cross branch access dari CMS.',
                    'Tidak bisa System Control Center.',
                ],
            ],
            [
                'role' => 'Manager',
                'level' => 'Branch kota/kab',
                'scope' => 'Area di bawah branch yang diberikan, misalnya STBKT, STBASB, STBBSK di bawah STB.',
                'can' => [
                    'Monitoring order, driver, revenue/report, area, dan operasional branch.',
                    'Create/edit user operasional dan driver dalam scope branch.',
                    'Melihat report dan export jika permission aktif.',
                ],
                'cannot' => [
                    'Tidak bisa mengakses branch lain kecuali dibuka lewat CMS lintas cabang.',
                    'Tidak bisa System Control Center.',
                ],
            ],
            [
                'role' => 'SPV',
                'level' => 'Area operasional',
                'scope' => 'Area yang diberikan, atau beberapa area jika branch scope diisi.',
                'can' => [
                    'Monitoring order area, approve/reject cancel, live chat/internal chat sesuai permission.',
                    'Klik Bayar/Lunas setoran driver dan save konfigurasi driver sesuai scope.',
                    'Suspend/unsuspend driver jika permission suspend aktif.',
                ],
                'cannot' => [
                    'Tidak bisa mengelola role di atasnya.',
                    'Tidak bisa System Control Center.',
                    'Tidak bisa akses area lain tanpa branch scope.',
                ],
            ],
            [
                'role' => 'Operator',
                'level' => 'Operasional shift',
                'scope' => 'Lintas area layanan sesuai kebutuhan piket.',
                'can' => [
                    'Manual order, monitor live order, assign/oper handle driver jika permission aktif.',
                    'Menangani chat customer dan internal chat.',
                    'Membantu order lintas area saat shift.',
                ],
                'cannot' => [
                    'Tidak bisa mengubah pricing, report finance, atau konfigurasi sistem.',
                    'Tidak bisa System Control Center.',
                ],
            ],
            [
                'role' => 'Eksekutor',
                'level' => 'Pelaksana lapangan',
                'scope' => 'Area yang diberikan.',
                'can' => [
                    'Monitor order dan menjalankan tugas eksekusi sesuai permission.',
                    'Manual order/assign driver jika permission aktif.',
                    'Internal chat dan komunikasi operasional.',
                ],
                'cannot' => [
                    'Tidak bisa mengelola role besar, pricing, report global, atau sistem.',
                ],
            ],
            [
                'role' => 'Web Admin',
                'level' => 'CMS konten',
                'scope' => 'Konten frontend/customer dan CMS tampilan.',
                'can' => [
                    'Kelola banner, home section, home item, announcement, dan konten publik.',
                ],
                'cannot' => [
                    'Tidak bisa mengelola order, driver, finance, pricing, atau System Control Center.',
                ],
            ],
            [
                'role' => 'CMS Editor',
                'level' => 'Editor konten',
                'scope' => 'Konten CMS yang diberikan.',
                'can' => [
                    'Edit konten CMS sesuai menu yang aktif.',
                ],
                'cannot' => [
                    'Tidak bisa mengakses operasi order, driver, pricing sensitif, finance, atau System Control Center.',
                ],
            ],
            [
                'role' => 'Driver',
                'level' => 'Aplikasi driver',
                'scope' => 'Area/cabang driver, atau all area jika diaktifkan pada konfigurasi driver.',
                'can' => [
                    'ON/OFF, menerima order yang valid, menjalankan order, chat customer/operator, melihat setoran.',
                    'Request order manual driver jika fitur aktif.',
                ],
                'cannot' => [
                    'Tidak bisa masuk backend/admin.',
                    'Tidak bisa mengambil order yang sudah accepted driver lain.',
                ],
            ],
            [
                'role' => 'Customer',
                'level' => 'Aplikasi customer',
                'scope' => 'Akun customer sendiri.',
                'can' => [
                    'Login Google, membuat order, chat JOJOBOT/CS/driver, melihat riwayat, rating, dan upload gambar.',
                ],
                'cannot' => [
                    'Tidak bisa masuk backend/admin.',
                    'Dapat dikunci dari Security Center jika terindikasi fake order.',
                ],
            ],
        ];
    }

    private function systemDocumentationRows(): array
    {
        return [
            [
                'title' => 'Dokumentasi Flow Sistem JOJO',
                'path' => 'backend/docs/dokumentasi-flow-sistem-jojo.md',
                'summary' => 'Ringkasan alur Customer FE, Driver FE, Admin FE, Laravel API, OSRM, AI parser, pricing, branch, area, dan report.',
            ],
            [
                'title' => 'Jojobot AI Pricing Flow',
                'path' => 'backend/docs/jojobot-ai-orchestra-spatial-pricing-engine.md',
                'summary' => 'Menjelaskan bahwa pricing dihitung deterministic dari OSRM, Master Ring, keyword rules, service fee, dan konfigurasi CMS; AI parser hanya membaca teks order.',
            ],
            [
                'title' => 'System Control Center Activation Flow',
                'path' => 'backend/docs/system-control-center-activation-flow.md',
                'summary' => 'Konsep aktivasi aplikasi model SaaS/license key, validasi server, grace period, dan fail-safe mode.',
            ],
            [
                'title' => 'Role Capability Matrix',
                'path' => 'backend/docs/system-control-center-role-capability-matrix.md',
                'summary' => 'Dokumentasi lokal role dan batasan akses yang juga diringkas di panel ini.',
            ],
        ];
    }

    private function apiDocumentationNote(): array
    {
        return [
            'status' => 'Belum dipasang di production',
            'recommendation' => 'Scramble/dedoc aman dipakai untuk Laravel 10+ sebagai generator OpenAPI, tetapi sebaiknya dipasang bertahap di staging/local dulu karena menambah package dan route dokumentasi baru.',
            'safe_flow' => 'Pasang package, batasi route docs dengan middleware admin, filter hanya API yang ingin dibuka, lalu baru expose ke System Control Center.',
            'routes' => '/docs/api dan /docs/api.json jika Scramble diaktifkan.',
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
        $search = trim($this->securitySearch);

        return User::query()
            ->where('role', '!=', UserRole::Admin->value)
            ->where(function ($query): void {
                $query
                    ->where('is_staff', true)
                    ->orWhere('role', UserRole::Customer->value);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('role', 'like', $like)
                        ->orWhereHas('branch', function ($query) use ($like): void {
                            $query
                                ->where('branch_code', 'like', $like)
                                ->orWhere('name', 'like', $like)
                                ->orWhere('area', 'like', $like);
                        });
                });
            })
            ->with('branch:id,branch_code,name,area')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'username' => $user->username,
                'role' => $user->role?->label() ?? (string) $user->role,
                'branch' => $user->branch?->display_name ?? '-',
                'is_suspended' => (bool) $user->is_suspended,
                'tokens' => $user->tokens()->count(),
                'updated_at' => $user->updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function auditRows(array $actions, int $limit): array
    {
        $search = trim($this->auditSearch);

        return AuditLog::query()
            ->with('user:id,name,email,role')
            ->whereIn('action', $actions)
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($query) use ($like): void {
                    $query
                        ->where('action', 'like', $like)
                        ->orWhere('subject_label', 'like', $like)
                        ->orWhere('subject_type', 'like', $like)
                        ->orWhere('metadata', 'like', $like)
                        ->orWhereHas('user', function ($query) use ($like): void {
                            $query
                                ->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('role', 'like', $like);
                        });
                });
            })
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
        return app(DatabaseBackupService::class)->rows(10);
    }

    private function createDatabaseBackup(string $prefix): array
    {
        return app(DatabaseBackupService::class)->create($prefix);
    }

    private function restoreDatabaseBackup(string $path): array
    {
        return app(DatabaseBackupService::class)->restore($path);
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

    private function brandingState(SettingService $settings): array
    {
        return [
            'branding_customer_app_name' => $settings->brandingAppName('customer'),
            'branding_customer_logo' => $this->normalizeUploadState($settings->get('branding_customer_logo')),
            'branding_customer_hero_image' => $this->normalizeUploadState($settings->get('branding_customer_hero_image')),
            'branding_customer_favicon' => $this->normalizeUploadState($settings->get('branding_customer_favicon')),
            'branding_customer_apple_touch_icon' => $this->normalizeUploadState($settings->get('branding_customer_apple_touch_icon')),
            'branding_customer_pwa_icon_192' => $this->normalizeUploadState($settings->get('branding_customer_pwa_icon_192')),
            'branding_customer_pwa_icon_512' => $this->normalizeUploadState($settings->get('branding_customer_pwa_icon_512')),
            'branding_driver_app_name' => $settings->brandingAppName('driver'),
            'branding_driver_logo' => $this->normalizeUploadState($settings->get('branding_driver_logo')),
            'branding_driver_favicon' => $this->normalizeUploadState($settings->get('branding_driver_favicon')),
            'branding_driver_apple_touch_icon' => $this->normalizeUploadState($settings->get('branding_driver_apple_touch_icon')),
            'branding_driver_pwa_icon_192' => $this->normalizeUploadState($settings->get('branding_driver_pwa_icon_192')),
            'branding_driver_pwa_icon_512' => $this->normalizeUploadState($settings->get('branding_driver_pwa_icon_512')),
            'branding_admin_app_name' => $settings->brandingAppName('admin'),
            'branding_admin_logo' => $this->normalizeUploadState($settings->get('branding_admin_logo')),
            'branding_admin_favicon' => $this->normalizeUploadState($settings->get('branding_admin_favicon')),
            'branding_admin_pwa_icon_192' => $this->normalizeUploadState($settings->get('branding_admin_pwa_icon_192')),
            'branding_admin_pwa_icon_512' => $this->normalizeUploadState($settings->get('branding_admin_pwa_icon_512')),
            'branding_backend_app_name' => $settings->brandingBackendName(),
        ];
    }

    private function brandingImageUpload(string $name, string $label, string $directory, string $helperText): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label($label)
            ->disk('public')
            ->directory('settings/branding/'.$directory)
            ->visibility('public')
            ->image()
            ->imagePreviewHeight('120')
            ->maxSize(2048)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->downloadable()
            ->openable()
            ->helperText($helperText);
    }

    private function brandingNameInput(string $name, string $label, string $default): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->label($label)
            ->default($default)
            ->required()
            ->maxLength(40)
            ->helperText('Nama ini tampil pada frontend dan nama install PWA.')
            ->columnSpanFull();
    }

    private function brandingPwaUpload(string $name, string $label, string $directory): Forms\Components\FileUpload
    {
        return Forms\Components\FileUpload::make($name)
            ->label($label)
            ->disk('public')
            ->directory('settings/branding/'.$directory)
            ->visibility('public')
            ->image()
            ->imagePreviewHeight('120')
            ->maxSize(2048)
            ->acceptedFileTypes(['image/png'])
            ->downloadable()
            ->openable()
            ->helperText('Gunakan PNG persegi sesuai ukuran agar icon install tetap tajam.');
    }

    private function normalizeUploadState(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)
                ->flatten()
                ->filter(fn (mixed $item): bool => filled($item))
                ->first();
        }

        if (! filled($value)) {
            return null;
        }

        $path = (string) $value;
        $path = preg_replace('#^https?://[^/]+/storage/#i', '', $path) ?? $path;
        $path = preg_replace('#^/?storage/#i', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    private function loadAutoBackupSettings(SettingService $settings): void
    {
        $this->autoBackupEnabled = $settings->bool('scc_auto_database_backup_enabled', false);
        $this->autoBackupTime = $this->normalizeBackupTime((string) $settings->get('scc_auto_database_backup_time', '02:00'));
        $this->autoBackupRetentionDays = max(1, min(180, $settings->int('scc_auto_database_backup_retention_days', 14)));
    }

    private function normalizeBackupTime(string $time): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $time) === 1 ? $time : '02:00';
    }

    private function backupDirectory(): string
    {
        return app(DatabaseBackupService::class)->directory();
    }

    private function humanFileSize(int $bytes): string
    {
        return app(DatabaseBackupService::class)->humanFileSize($bytes);
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
