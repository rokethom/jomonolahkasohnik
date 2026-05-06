<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\MultiOrderService;
use Tests\TestCase;

class MultiOrderServiceTest extends TestCase
{
    public function test_bearing_calculation_points_east(): void
    {
        $bearing = app(MultiOrderService::class)->calculateBearing(-6.2, 106.8, -6.2, 106.9);

        $this->assertGreaterThan(89, $bearing);
        $this->assertLessThan(91, $bearing);
    }

    public function test_same_direction_uses_45_degree_threshold(): void
    {
        $service = app(MultiOrderService::class);
        $orderA = new Order([
            'pickup_lat' => -6.2,
            'pickup_lng' => 106.8,
            'destination_lat' => -6.2,
            'destination_lng' => 106.9,
        ]);
        $orderB = new Order([
            'pickup_lat' => -6.21,
            'pickup_lng' => 106.81,
            'destination_lat' => -6.21,
            'destination_lng' => 106.91,
        ]);
        $orderC = new Order([
            'pickup_lat' => -6.2,
            'pickup_lng' => 106.8,
            'destination_lat' => -6.3,
            'destination_lng' => 106.8,
        ]);

        $this->assertTrue($service->isSameDirection($orderA, $orderB));
        $this->assertFalse($service->isSameDirection($orderA, $orderC));
    }
}
