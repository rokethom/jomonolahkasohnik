<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\GeofenceArea;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_branch_uses_pickup_area_before_destination_area(): void
    {
        $situbondo = Branch::query()->create([
            'branch_code' => 'STBKT-ORDER',
            'name' => 'Situbondo',
            'area' => 'Kota Order',
            'latitude' => -7.70686204,
            'longitude' => 114.00550184,
            'radius_km' => 5,
        ]);

        $asembagus = Branch::query()->create([
            'branch_code' => 'STBASB-ORDER',
            'name' => 'Situbondo',
            'area' => 'Asembagus Order',
            'latitude' => -7.75086000,
            'longitude' => 114.21561000,
            'radius_km' => 5,
        ]);

        GeofenceArea::query()->create([
            'branch_id' => $situbondo->id,
            'name' => 'Situbondo Kota',
            'center_latitude' => -7.70686204,
            'center_longitude' => 114.00550184,
            'radius_meters' => 5000,
            'is_active' => true,
            'priority' => 10,
        ]);

        GeofenceArea::query()->create([
            'branch_id' => $asembagus->id,
            'name' => 'Asembagus',
            'center_latitude' => -7.75086000,
            'center_longitude' => 114.21561000,
            'radius_meters' => 5000,
            'is_active' => true,
            'priority' => 10,
        ]);

        $branchId = app(OrderService::class)->resolveTargetBranchId([
            'pickup_lat' => -7.75086000,
            'pickup_lng' => 114.21561000,
            'destination_lat' => -7.70686204,
            'destination_lng' => 114.00550184,
        ], $situbondo->id);

        $this->assertSame($asembagus->id, $branchId);
    }
}
