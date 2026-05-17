<?php

namespace App\Services;

use App\Models\AiLog;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiLogService
{
    public function start(array $payload): ?AiLog
    {
        if (! Schema::hasTable('ai_logs')) {
            return null;
        }

        return AiLog::query()->create([
            ...$payload,
            'status' => AiLog::STATUS_STARTED,
            'queue' => $payload['queue'] ?? 'ai',
            'started_at' => now(),
        ]);
    }

    public function success(?AiLog $log, array $output = [], ?string $message = null): void
    {
        if (! $log) {
            return;
        }

        $startedAt = $log->started_at ?? now();
        $finishedAt = now();

        $log->forceFill([
            'status' => AiLog::STATUS_SUCCESS,
            'output_payload' => $output,
            'message' => $message,
            'duration_ms' => max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'finished_at' => $finishedAt,
        ])->save();
    }

    public function failed(?AiLog $log, Throwable $exception, array $output = []): void
    {
        if (! $log) {
            return;
        }

        $startedAt = $log->started_at ?? now();
        $finishedAt = now();

        $log->forceFill([
            'status' => AiLog::STATUS_FAILED,
            'output_payload' => $output,
            'error_message' => $exception->getMessage(),
            'duration_ms' => max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'finished_at' => $finishedAt,
        ])->save();
    }
}
