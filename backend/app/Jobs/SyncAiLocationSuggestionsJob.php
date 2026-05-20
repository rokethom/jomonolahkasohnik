<?php

namespace App\Jobs;

use App\Services\AiLocationLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAiLocationSuggestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(private readonly int $limit = 300)
    {
        $this->onQueue('ai');
    }

    public function handle(AiLocationLearningService $service): void
    {
        $result = $service->syncFromManualOrdersAndLivePriceReviews($this->limit);

        Log::channel('ai')->info('ai_location_suggestions.synced', [
            'limit' => $this->limit,
            'result' => $result,
        ]);
    }
}
