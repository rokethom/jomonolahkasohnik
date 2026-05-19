<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function create(string $prefix): array
    {
        try {
            File::ensureDirectoryExists($this->directory(), 0775, true);
        } catch (\Throwable $exception) {
            Log::warning('database_backup.ensure_directory_failed', [
                'directory' => $this->directory(),
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'Folder backup tidak bisa ditulis: '.$exception->getMessage(),
            ];
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$prefix.'-'.now()->format('Ymd-His').'.sql';
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

        try {
            $process = new Process($command);
            $process->setTimeout(180);
            $process->run();
        } catch (\Throwable $exception) {
            Log::warning('database_backup.mysqldump_failed', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'mysqldump gagal dijalankan: '.$exception->getMessage(),
            ];
        }

        if (! $process->isSuccessful()) {
            return ['ok' => false, 'message' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'mysqldump gagal.'];
        }

        try {
            File::put($path, $process->getOutput());
        } catch (\Throwable $exception) {
            Log::warning('database_backup.write_failed', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'File backup gagal ditulis: '.$exception->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'message' => basename($path).' dibuat.',
            'file' => basename($path),
            'path' => $path,
            'size' => $this->humanFileSize(File::size($path)),
        ];
    }

    public function restore(string $path): array
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

    public function rows(int $limit = 10): array
    {
        File::ensureDirectoryExists($this->directory());

        return collect(File::files($this->directory()))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.sql'))
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->take($limit)
            ->map(fn ($file): array => [
                'name' => $file->getFilename(),
                'size' => $this->humanFileSize($file->getSize()),
                'updated_at' => date('Y-m-d H:i:s', $file->getMTime()),
            ])
            ->values()
            ->all();
    }

    public function latestAutoBackup(): ?array
    {
        File::ensureDirectoryExists($this->directory());

        $file = collect(File::files($this->directory()))
            ->filter(fn ($file): bool => str_starts_with($file->getFilename(), 'auto-backup-') && str_ends_with($file->getFilename(), '.sql'))
            ->sortByDesc(fn ($file): int => $file->getMTime())
            ->first();

        if (! $file) {
            return null;
        }

        return [
            'name' => $file->getFilename(),
            'size' => $this->humanFileSize($file->getSize()),
            'updated_at' => date('Y-m-d H:i:s', $file->getMTime()),
            'timestamp' => $file->getMTime(),
        ];
    }

    public function autoBackupAlreadyCreatedToday(): bool
    {
        $latest = $this->latestAutoBackup();

        return $latest !== null && date('Y-m-d', (int) $latest['timestamp']) === now()->toDateString();
    }

    public function cleanupAutoBackupsOlderThan(int $days): int
    {
        File::ensureDirectoryExists($this->directory());

        $days = max(1, $days);
        $deleted = 0;
        $threshold = now()->subDays($days)->getTimestamp();

        foreach (File::files($this->directory()) as $file) {
            if (! str_starts_with($file->getFilename(), 'auto-backup-') || ! str_ends_with($file->getFilename(), '.sql')) {
                continue;
            }

            if ($file->getMTime() < $threshold) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }

    public function directory(): string
    {
        return storage_path('app/backups/system-control');
    }

    public function humanFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
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
        } catch (\Throwable $exception) {
            return ['ok' => false, 'output' => $exception->getMessage()];
        }
    }
}
