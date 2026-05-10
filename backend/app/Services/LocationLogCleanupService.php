<?php

namespace App\Services;

use App\Models\LocationLog;
use Illuminate\Support\Facades\Log;

class LocationLogCleanupService
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    /**
     * @return array{enabled: bool, normal_deleted: int, suspicious_deleted: int, normal_retention_days: int, suspicious_retention_days: int, batch_limit: int}
     */
    public function prune(): array
    {
        $enabled = $this->settings->bool('location_log_cleanup_enabled', true);
        $normalRetentionDays = max(1, min(365, $this->settings->int('location_log_retention_days', 14)));
        $suspiciousRetentionDays = max($normalRetentionDays, min(730, $this->settings->int('location_log_suspicious_retention_days', 30)));
        $batchLimit = max(100, min(10000, $this->settings->int('location_log_cleanup_batch_limit', 1000)));

        $result = [
            'enabled' => $enabled,
            'normal_deleted' => 0,
            'suspicious_deleted' => 0,
            'normal_retention_days' => $normalRetentionDays,
            'suspicious_retention_days' => $suspiciousRetentionDays,
            'batch_limit' => $batchLimit,
        ];

        if (! $enabled) {
            return $result;
        }

        $result['normal_deleted'] = $this->deleteBatch(
            LocationLog::query()
                ->where('created_at', '<', now()->subDays($normalRetentionDays))
                ->where('is_suspicious', false)
                ->where('is_mock_location', false),
            $batchLimit,
        );

        $remainingBatch = max(0, $batchLimit - $result['normal_deleted']);
        $result['suspicious_deleted'] = $remainingBatch > 0
            ? $this->deleteBatch(
                LocationLog::query()
                    ->where('created_at', '<', now()->subDays($suspiciousRetentionDays))
                    ->where(function ($query): void {
                        $query->where('is_suspicious', true)
                            ->orWhere('is_mock_location', true);
                    }),
                $remainingBatch,
            )
            : 0;

        if (($result['normal_deleted'] + $result['suspicious_deleted']) > 0) {
            Log::info('location_logs.cleanup_completed', $result);
        }

        return $result;
    }

    private function deleteBatch($query, int $limit): int
    {
        $ids = (clone $query)
            ->oldest('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return LocationLog::query()
            ->whereIn('id', $ids)
            ->delete();
    }
}
