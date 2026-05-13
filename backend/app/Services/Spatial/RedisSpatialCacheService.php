<?php

declare(strict_types=1);

namespace App\Services\Spatial;

use App\Models\GeojsonRegion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RedisSpatialCacheService
{
    public const TTL = 3600;

    public function rememberCandidates(float $lat, float $lng, callable $resolver): Collection
    {
        $key = 'jojobot:spatial:candidates:'.round($lat, 3).':'.round($lng, 3);

        return Cache::remember($key, self::TTL, $resolver);
    }

    public function forgetRegion(GeojsonRegion $region): void
    {
        Cache::forget('jojobot:geojson_region:'.$region->id);
    }
}
