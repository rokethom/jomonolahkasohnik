<?php

declare(strict_types=1);

namespace App\AI\Agents;

use App\DTOs\Pricing\PricingResultData;
use App\Jobs\RecordAiAnalyticsJob;

class AnalyticsAgent
{
    public function handle(PricingResultData $result): void
    {
        RecordAiAnalyticsJob::dispatch($result->toArray());
    }
}
