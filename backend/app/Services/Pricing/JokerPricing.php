<?php

namespace App\Services\Pricing;

class JokerPricing
{
    public function calculate(float $distance): array
    {
        $billingDistance = $this->roundDistance($distance);

        $tarif = match (true) {
            $distance <= 3.5 => 25000,
            $distance <= 10.5 => (int) (($billingDistance * 5000) + 10000),
            default => (int) (($billingDistance * 4000) + 20000),
        };

        return [
            'distance' => $distance,
            'billing_distance' => $billingDistance,
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
