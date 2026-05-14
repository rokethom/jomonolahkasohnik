<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Services\GeocodingService;
use App\Services\Geojson\BoundaryGeojsonRegionService;
use App\Services\Geojson\GeojsonParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GeojsonParserServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_feature_collection_is_parsed_into_table_rows(): void
    {
        $geojson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['name' => 'Panarukan'],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[
                            [113.918, -7.702],
                            [113.919, -7.702],
                            [113.919, -7.703],
                            [113.918, -7.703],
                            [113.918, -7.702],
                        ]],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['name' => 'Agel'],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[
                            [114.172, -7.718],
                            [114.173, -7.718],
                            [114.173, -7.719],
                            [114.172, -7.719],
                            [114.172, -7.718],
                        ]],
                    ],
                ],
            ],
        ];

        $rows = app(GeojsonParserService::class)->parseRows($geojson);

        $this->assertCount(2, $rows);
        $this->assertSame('Panarukan', $rows[0]['name']);
        $this->assertSame('Agel', $rows[1]['name']);
        $this->assertSame('Polygon', $rows[0]['geometry_type']);
        $this->assertArrayHasKey('centroid_lat', $rows[0]);
        $this->assertArrayHasKey('centroid_lng', $rows[0]);
    }

    public function test_boundary_generator_creates_polygon_and_inside_region_rows(): void
    {
        $branch = Branch::query()->create([
            'branch_code' => 'STBKT',
            'name' => 'Situbondo',
            'area' => 'Kota',
            'latitude' => -7.7063,
            'longitude' => 114.0098,
            'radius_km' => 20,
            'is_active' => true,
        ]);

        GeojsonRegion::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Panji Dalam',
            'geojson' => ['type' => 'Polygon', 'coordinates' => [[[114.009, -7.707], [114.011, -7.707], [114.011, -7.709], [114.009, -7.709], [114.009, -7.707]]]],
            'geometry_type' => 'Polygon',
            'coordinates' => [[
                ['lat' => -7.707, 'lng' => 114.009],
                ['lat' => -7.707, 'lng' => 114.011],
                ['lat' => -7.709, 'lng' => 114.011],
                ['lat' => -7.709, 'lng' => 114.009],
                ['lat' => -7.707, 'lng' => 114.009],
            ]],
            'centroid_lat' => -7.708,
            'centroid_lng' => 114.010,
            'min_lat' => -7.709,
            'max_lat' => -7.707,
            'min_lng' => 114.009,
            'max_lng' => 114.011,
            'version' => 1,
            'is_active' => true,
        ]);

        $geocoding = $this->mock(GeocodingService::class);
        $geocoding->shouldReceive('geocodeNearBranchGoogleOnly')->andReturnUsing(
            fn (string $address): array => match ($address) {
                'utara' => ['lat' => -7.700, 'lng' => 114.010, 'formatted_address' => 'utara', 'query' => 'utara'],
                'selatan' => ['lat' => -7.720, 'lng' => 114.010, 'formatted_address' => 'selatan', 'query' => 'selatan'],
                'barat' => ['lat' => -7.710, 'lng' => 114.000, 'formatted_address' => 'barat', 'query' => 'barat'],
                'timur' => ['lat' => -7.710, 'lng' => 114.020, 'formatted_address' => 'timur', 'query' => 'timur'],
            },
        );

        $rows = app(BoundaryGeojsonRegionService::class)->generateRows([
            'name' => 'Batas Ring 1',
            'branch_id' => $branch->id,
            'boundary_north' => 'utara',
            'boundary_south' => 'selatan',
            'boundary_west' => 'barat',
            'boundary_east' => 'timur',
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('Batas Ring 1', $rows[0]['name']);
        $this->assertSame('Panji Dalam', $rows[1]['name']);
        $this->assertSame('Polygon', $rows[0]['geometry_type']);
    }
}
