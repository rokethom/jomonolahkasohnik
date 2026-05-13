<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeojsonRegion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GeojsonRegion> */
class GeojsonRegionFactory extends Factory
{
    protected $model = GeojsonRegion::class;

    public function definition(): array
    {
        $geojson = [
            'type' => 'Polygon',
            'coordinates' => [[[113.9, -7.8], [114.1, -7.8], [114.1, -7.6], [113.9, -7.6], [113.9, -7.8]]],
        ];

        return [
            'name' => $this->faker->city(),
            'geojson' => $geojson,
            'geometry_type' => 'Polygon',
            'coordinates' => [[
                ['lat' => -7.8, 'lng' => 113.9],
                ['lat' => -7.8, 'lng' => 114.1],
                ['lat' => -7.6, 'lng' => 114.1],
                ['lat' => -7.6, 'lng' => 113.9],
            ]],
            'centroid_lat' => -7.7,
            'centroid_lng' => 114,
            'min_lat' => -7.8,
            'max_lat' => -7.6,
            'min_lng' => 113.9,
            'max_lng' => 114.1,
            'version' => 1,
            'is_active' => true,
        ];
    }
}
