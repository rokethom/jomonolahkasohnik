<?php

namespace App\Jobs;

use App\Services\DriverFinanceService;
use App\Services\SuspendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnforceDriverSuspensionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(DriverFinanceService $finance, SuspendService $suspensions): void
    {
        $finance->enforceUnpaidSuspensions($suspensions);
        $suspensions->deletePermanentExpiredDrivers();
    }
}
