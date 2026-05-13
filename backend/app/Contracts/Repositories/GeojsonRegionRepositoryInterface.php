<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;

interface GeojsonRegionRepositoryInterface
{
    /** @return Collection<int, \App\Models\GeojsonRegion> */
    public function activeCandidatesForPoint(float $lat, float $lng): Collection;
}
