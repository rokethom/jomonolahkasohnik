<?php

namespace Tests\Unit;

use App\Services\Geojson\GeojsonParserService;
use Tests\TestCase;

class GeojsonParserServiceTest extends TestCase
{
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
}
