<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Distance\DistanceService;
use PHPUnit\Framework\TestCase;

class DistanceServiceTest extends TestCase
{
    public function test_haversine_distance_is_calculated_in_kilometers(): void
    {
        $distance = (new DistanceService())->calculateDistanceKm(-7.8921, 113.8211, -7.9121, 113.8511);

        $this->assertGreaterThan(3.9, $distance);
        $this->assertLessThan(4.1, $distance);
    }
}
