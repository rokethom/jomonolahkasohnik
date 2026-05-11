<?php

namespace App\Services\Pricing;

class JokerPricing
{
    public function calculate(float $distance): array
    {
        $billingDistance = $this->roundDistance($distance);
        $extraDistance = 0;
        $tarif = 25000;

        if ($distance > 3) {
            $extraDistance = max(1, (int) ceil($distance - 3));
            $tarif += $extraDistance * 5000;
        }

        return [
            'distance' => $distance,
            'billing_distance' => $billingDistance,
            'base_tarif' => 25000,
            'extra_distance' => $extraDistance,
            'per_km_rate' => 5000,
            'tarif' => $tarif,
        ];
    }

    public function roundDistance(float $distance): int
    {
        $floor = (int) floor($distance);
        $decimal = $distance - $floor;

        return $decimal <= 0.5 ? $floor : $floor + 1;
    }
}
