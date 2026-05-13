<?php

declare(strict_types=1);

namespace App\Jobs;

use App\AI\Analytics\PricingAnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordAiAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $payload)
    {
    }

    public function handle(PricingAnalyticsService $analytics): void
    {
        $analytics->record($this->payload);
    }
}
