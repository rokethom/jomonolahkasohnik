<?php

namespace App\Jobs;

use App\Services\AiAliasMapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAiAliasMapsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(private readonly ?int $branchId = null)
    {
        $this->onQueue('ai');
    }

    public function handle(AiAliasMapService $service): void
    {
        $result = $service->generateFromGeojsonAndOrders($this->branchId);

        Log::channel('ai')->info('ai_alias_maps.generated', [
            'branch_id' => $this->branchId,
            'result' => $result,
        ]);
    }
}
