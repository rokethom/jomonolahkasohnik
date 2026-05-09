<?php

namespace App\Jobs;

use App\Services\SLAService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnforceChatSlaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(SLAService $slaService): void
    {
        $slaService->enforceUnansweredOperatorChats();
    }
}
