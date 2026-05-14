<?php

namespace Tests\Unit;

use App\Services\Pricing\DistanceCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DistanceCalculatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_driving_distance_falls_back_to_osrm_when_google_fails(): void
    {
        config(['services.google.maps_key' => '']);
        putenv('GOOGLE_MAPS_API_KEY=');

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
