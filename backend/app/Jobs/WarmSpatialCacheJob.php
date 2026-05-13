<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GeojsonRegion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class WarmSpatialCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Cache::remember('jojobot:geojson_regions:active', 3600, fn () => GeojsonRegion::query()
            ->with(['branch', 'area'])
            ->where('is_active', true)
            ->get());
    }
}
