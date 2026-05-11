<?php

namespace App\Listeners;

use App\Services\HermesSafetyAssistantService;
use Illuminate\Queue\Events\JobFailed;
use Throwable;

class ReportFailedJobToHermes
{
    public function handle(JobFailed $event): void
    {
        try {
            app(HermesSafetyAssistantService::class)->analyzeError([
                'source' => 'failed_job',
                'service' => config('app.name', 'jojo-backend'),
                'environment' => app()->environment(),
                'error_message' => $event->exception->getMessage(),
                'stack_trace' => mb_substr($event->exception->getTraceAsString(), 0, 8000),
                'job' => [
                    'connection' => $event->connectionName,
                    'queue' => $event->job->getQueue(),
                    'name' => $event->job->resolveName(),
                    'attempts' => $event->job->attempts(),
                ],
            ], 'safety_failed_job');
        } catch (Throwable) {
            // Exception reporting must never break queue failure handling.
        }
    }
}
