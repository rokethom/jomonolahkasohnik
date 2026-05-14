<?php

namespace Tests\Unit;

use App\Services\Pricing\DistanceCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

class DistanceCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_driving_distance_uses_osrm_first(): void
    {
        Http::fake([
            'router.project-osrm.org/*' => Http::response([
                'routes' => [
                    ['distance' => 5900],
                ],
            ], 200),
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => ['value' => 6200],
                    ]],
                ]],
            ], 200),
        ]);

        $distance = app(DistanceCalculator::class)->drivingDistance(-7.7063, 114.0098, -7.7034, 114.0500);

        $this->assertSame(5.9, $distance);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'maps.googleapis.com'));
    }

    public function test_driving_distance_falls_back_to_google_when_enabled_and_osrm_fails(): void
    {
        app(\App\Services\SettingService::class)->set('google_maps_distance_enabled', true);

        Http::fake([
            'router.project-osrm.org/*' => Http::response([], 500),
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'rows' => [[
                    'elements' => [[
                        'status' => 'OK',
                        'distance' => ['value' => 6200],
                    ]],
                ]],
            ], 200),
        ]);

        $distance = app(DistanceCalculator::class)->drivingDistance(-7.7063, 114.0098, -7.7034, 114.0500);

        $this->assertSame(6.2, $distance);
    }
}
