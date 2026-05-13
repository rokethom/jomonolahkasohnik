<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Spatial\PointInPolygonService;
use PHPUnit\Framework\TestCase;

class PointInPolygonServiceTest extends TestCase
{
    public function test_ray_casting_detects_point_inside_polygon(): void
    {
        $polygon = [[
            ['lat' => -7.8, 'lng' => 113.9],
            ['lat' => -7.8, 'lng' => 114.1],
            ['lat' => -7.6, 'lng' => 114.1],
            ['lat' => -7.6, 'lng' => 113.9],
        ]];

        $service = new PointInPolygonService();

        $this->assertTrue($service->contains(-7.7, 114.0, $polygon));
        $this->assertFalse($service->contains(-8.0, 114.0, $polygon));
    }
}
