<?php

namespace App\Jobs;

use App\Services\AnalyticsAggregationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class AggregateAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->onQueue('default');
    }

    public function handle(AnalyticsAggregationService $analytics): void
    {
        $analytics->aggregateRange(Carbon::parse($this->from), Carbon::parse($this->to));
    }
}
