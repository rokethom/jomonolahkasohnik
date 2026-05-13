<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiRule;
use App\Models\PricingRing;
use Illuminate\Database\Seeder;

class AiSpatialPricingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Ring 1', 'min_km' => 0, 'max_km' => 4, 'formula_type' => 'FIXED', 'base_price' => 6000, 'service_fee' => 1000, 'per_km_price' => null, 'deduction' => 0, 'priority' => 300],
            ['name' => 'Ring 2', 'min_km' => 4.1, 'max_km' => 9, 'formula_type' => 'FIXED', 'base_price' => 12000, 'service_fee' => 1000, 'per_km_price' => null, 'deduction' => 0, 'priority' => 200],
            ['name' => 'Ring 3', 'min_km' => 9.01, 'max_km' => null, 'formula_type' => 'DISTANCE', 'base_price' => 0, 'service_fee' => 0, 'per_km_price' => 1900, 'deduction' => 7000, 'priority' => 100],
        ] as $ring) {
            PricingRing::query()->updateOrCreate(['name' => $ring['name']], [...$ring, 'is_active' => true, 'version' => 1]);
        }

        AiModel::query()->updateOrCreate(
            ['model_key' => 'native-laravel-spatial-pricing-v1'],
            ['name' => 'Native Laravel Spatial Pricing', 'provider' => 'native_laravel', 'configuration' => ['cache_ttl' => 3600], 'is_active' => true],
        );

        AiRule::query()->updateOrCreate(
            ['code' => 'pricing-distance-ring-v1'],
            ['name' => 'Distance Based Pricing Ring', 'agent' => 'PricingAgent', 'conditions' => ['pricing_source' => 'haversine'], 'actions' => ['select_ring_by_distance' => true], 'priority' => 100, 'is_active' => true],
        );
    }
}
