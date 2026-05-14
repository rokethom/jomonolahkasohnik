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

    public function test_driving_distance_uses_google_first_for_maps_accurate_distance(): void
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

        $this->assertSame(6.2, $distance);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'router.project-osrm.org'));
    }

    public function test_driving_distance_falls_back_to_osrm_when_google_fails(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'], 200),
            'router.project-osrm.org/*' => Http::response([
                'routes' => [
                    ['distance' => 5900],
                ],
            ], 200),
        ]);

        $distance = app(DistanceCalculator::class)->drivingDistance(-7.7063, 114.0098, -7.7034, 114.0500);

        $this->assertSame(5.9, $distance);
    }
}
