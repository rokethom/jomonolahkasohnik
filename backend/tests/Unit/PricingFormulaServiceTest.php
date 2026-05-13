<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PricingRing;
use App\Services\Formula\PricingFormulaService;
use PHPUnit\Framework\TestCase;

class PricingFormulaServiceTest extends TestCase
{
    public function test_fixed_ring_adds_service_fee(): void
    {
        $ring = new PricingRing(['formula_type' => 'FIXED', 'base_price' => 6000, 'service_fee' => 1000]);

        $this->assertSame(7000, (new PricingFormulaService())->calculate($ring, 3)['price']);
    }

    public function test_distance_ring_uses_distance_formula(): void
    {
        $ring = new PricingRing(['formula_type' => 'DISTANCE', 'per_km_price' => 1900, 'deduction' => 7000]);

        $this->assertSame(15800, (new PricingFormulaService())->calculate($ring, 12)['price']);
    }
}
