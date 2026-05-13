<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PricingRing;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PricingRing> */
class PricingRingFactory extends Factory
{
    protected $model = PricingRing::class;

    public function definition(): array
    {
        return [
            'name' => 'Ring '.$this->faker->unique()->numberBetween(10, 99),
            'min_km' => 0,
            'max_km' => 4,
            'formula_type' => 'FIXED',
            'base_price' => 6000,
            'service_fee' => 1000,
            'per_km_price' => null,
            'deduction' => 0,
            'priority' => 100,
            'version' => 1,
            'is_active' => true,
        ];
    }
}
