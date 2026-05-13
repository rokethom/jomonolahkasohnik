<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PricingRing;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SpatialPricingApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_v1_pricing_calculate_uses_haversine_and_pricing_ring(): void
    {
        PricingRing::query()->delete();
        PricingRing::query()->create(['name' => 'Ring 3', 'min_km' => 9.01, 'max_km' => null, 'formula_type' => 'DISTANCE', 'base_price' => 0, 'service_fee' => 0, 'per_km_price' => 1900, 'deduction' => 7000, 'priority' => 100, 'version' => 1, 'is_active' => true]);

        $response = $this->postJson('/api/v1/pricing/calculate', [
            'pickup_latitude' => -7.7,
            'pickup_longitude' => 114.0,
            'destination_latitude' => -7.8079,
            'destination_longitude' => 114.0,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pricing_ring', 'Ring 3')
            ->assertJsonPath('data.price', 15800);
    }
}
