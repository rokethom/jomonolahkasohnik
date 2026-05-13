<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\PricingRing;
use Illuminate\Support\Collection;

interface PricingRingRepositoryInterface
{
    /** @return Collection<int, PricingRing> */
    public function activeRings(): Collection;

    public function findForDistance(float $distanceKm): ?PricingRing;
}
