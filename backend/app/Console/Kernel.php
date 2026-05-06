<?php

namespace App\Console;

use App\Jobs\CancelExpiredOrdersJob;
use App\Jobs\EnforceDriverSuspensionsJob;
use App\Jobs\PruneExpiredHomeItemsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new CancelExpiredOrdersJob())->everyMinute()->withoutOverlapping();
        $schedule->job(new EnforceDriverSuspensionsJob())->dailyAt('00:10')->withoutOverlapping();
        $schedule->job(new PruneExpiredHomeItemsJob())->dailyAt('00:20')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
