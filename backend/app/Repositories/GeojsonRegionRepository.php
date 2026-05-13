<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\GeojsonRegionRepositoryInterface;
use App\Models\GeojsonRegion;
use Illuminate\Support\Collection;

class GeojsonRegionRepository implements GeojsonRegionRepositoryInterface
{
    public function activeCandidatesForPoint(float $lat, float $lng): Collection
    {
        return GeojsonRegion::query()
            ->with(['branch', 'area'])
            ->active()
            ->containingBoundingBox($lat, $lng)
            ->orderByDesc('version')
            ->get();
    }
}
