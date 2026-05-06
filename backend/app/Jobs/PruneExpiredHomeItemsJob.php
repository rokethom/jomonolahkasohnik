<?php

namespace App\Jobs;

use App\Models\HomeItem;
use App\Services\CmsHomeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneExpiredHomeItemsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(CmsHomeService $cms): void
    {
        HomeItem::query()
            ->whereNotNull('end_date')
            ->where('end_date', '<', now())
            ->chunkById(50, function ($items): void {
                foreach ($items as $item) {
                    $item->delete();
                }
            });

        $cms->clearCache();
    }
}
