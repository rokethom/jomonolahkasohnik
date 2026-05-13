<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\Repositories\PricingRingRepositoryInterface;
use App\Models\PricingRing;
use RuntimeException;

class PricingRuleService
{
    public function __construct(private readonly PricingRingRepositoryInterface $rings)
    {
    }

    public function resolveRing(float $distanceKm): PricingRing
    {
        $ring = $this->rings->findForDistance($distanceKm);

        if (! $ring) {
            throw new RuntimeException('Pricing ring aktif belum tersedia untuk jarak '.round($distanceKm, 2).' KM.');
        }

        return $ring;
    }
}
