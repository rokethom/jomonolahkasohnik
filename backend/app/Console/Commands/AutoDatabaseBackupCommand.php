<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\DatabaseBackupService;
use App\Services\SettingService;
use Illuminate\Console\Command;

class AutoDatabaseBackupCommand extends Command
{
    protected $signature = 'system:auto-database-backup {--force : Jalankan walau belum masuk jadwal atau hari ini sudah ada backup}';

    protected $description = 'Create automatic database backup controlled from System Control Center settings.';

    public function handle(SettingService $settings, DatabaseBackupService $backups): int
    {
        if (! $settings->bool('scc_auto_database_backup_enabled', false) && ! $this->option('force')) {
            $this->info('Auto backup database nonaktif.');
            return self::SUCCESS;
        }

        $time = $this->normalizeTime((string) $settings->get('scc_auto_database_backup_time', '02:00'));
        if (! $this->option('force') && now()->format('H:i') < $time) {
            $this->info("Belum masuk jadwal backup {$time}.");
            return self::SUCCESS;
        }

        if (! $this->option('force') && $backups->autoBackupAlreadyCreatedToday()) {
            $this->info('Auto backup hari ini sudah dibuat.');
            return self::SUCCESS;
        }

        $result = $backups->create('auto-backup');
        $deleted = $backups->cleanupAutoBackupsOlderThan($settings->int('scc_auto_database_backup_retention_days', 14));

        AuditLog::query()->create([
            'user_id' => null,
            'action' => 'system_control_auto_backup_database',
            'subject_type' => self::class,
            'subject_id' => null,
            'subject_label' => 'System Control Center Auto Backup',
            'metadata' => [
                'result' => $result,
                'deleted_old_auto_backups' => $deleted,
                'scheduled_time' => $time,
            ],
        ]);

        $this->{$result['ok'] ? 'info' : 'error'}($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time;
        }

        return '02:00';
    }
}
