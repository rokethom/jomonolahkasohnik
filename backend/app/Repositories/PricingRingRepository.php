<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\PricingRingRepositoryInterface;
use App\Models\PricingRing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PricingRingRepository implements PricingRingRepositoryInterface
{
    public function activeRings(): Collection
    {
        return Cache::remember('jojobot:pricing_rings:active', 3600, fn (): Collection => PricingRing::query()
            ->activeForDate()
            ->orderByDesc('priority')
            ->orderBy('min_km')
            ->get());
    }

    public function findForDistance(float $distanceKm): ?PricingRing
    {
        return $this->activeRings()
            ->first(fn (PricingRing $ring): bool => $distanceKm >= (float) $ring->min_km
                && ($ring->max_km === null || $distanceKm <= (float) $ring->max_km));
    }
}
