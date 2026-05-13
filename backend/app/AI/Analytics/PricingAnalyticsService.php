<?php

declare(strict_types=1);

namespace App\AI\Analytics;

use App\Models\AiLog;

class PricingAnalyticsService
{
    public function record(array $payload): void
    {
        AiLog::query()->create([
            'workflow' => 'pricing.calculate',
            'agent' => 'AnalyticsAgent',
            'status' => 'success',
            'request_payload' => null,
            'response_payload' => $payload,
            'duration_ms' => (int) data_get($payload, 'workflow.duration_ms', 0),
        ]);
    }
}
