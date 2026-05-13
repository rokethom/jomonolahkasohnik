<?php

declare(strict_types=1);

namespace App\Services\Formula;

use App\Models\PricingRing;

class PricingFormulaService
{
    public function calculate(PricingRing $ring, float $distanceKm): array
    {
        if ($ring->formula_type === 'DISTANCE') {
            $price = max(0, (int) ceil(($distanceKm * (int) $ring->per_km_price) - (int) $ring->deduction));

            return [
                'price' => $price,
                'formula' => '('.round($distanceKm, 2).' * '.(int) $ring->per_km_price.') - '.(int) $ring->deduction,
            ];
        }

        return [
            'price' => (int) $ring->base_price + (int) $ring->service_fee,
            'formula' => (int) $ring->base_price.' + '.(int) $ring->service_fee,
        ];
    }
}
