<?php

declare(strict_types=1);

namespace App\Services\Spatial;

use App\Contracts\Repositories\BranchRepositoryInterface;
use App\Contracts\Repositories\GeojsonRegionRepositoryInterface;
use App\DTOs\Pricing\SpatialDetectionData;
use App\Models\GeojsonRegion;

class SpatialDetectionService
{
    public function __construct(
        private readonly GeojsonRegionRepositoryInterface $regions,
        private readonly BranchRepositoryInterface $branches,
        private readonly PointInPolygonService $pointInPolygon,
        private readonly RedisSpatialCacheService $cache,
    ) {
    }

    public function detect(float $lat, float $lng): SpatialDetectionData
    {
        $candidates = $this->cache->rememberCandidates(
            $lat,
            $lng,
            fn () => $this->regions->activeCandidatesForPoint($lat, $lng),
        );

        $region = $candidates->first(fn (GeojsonRegion $region): bool => $this->pointInPolygon->contains(
            $lat,
            $lng,
            $region->coordinates ?? [],
        ));

        if ($region instanceof GeojsonRegion) {
            return new SpatialDetectionData($region->branch, $region->area, $region, 'geojson_region');
        }

        return new SpatialDetectionData($this->branches->nearestActive($lat, $lng), null, null, 'nearest_branch');
    }
}
