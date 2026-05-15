<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Pricing\ServiceFeeCalculator;
use Illuminate\Support\Facades\DB;

class DriverRequestOrderService
{
    public function __construct(
        private readonly ServiceParserService $parser,
        private readonly OrderCodeGenerator $codeGenerator,
        private readonly MultiOrderService $multiOrder,
        private readonly ServiceFeeCalculator $serviceFees,
    ) {
    }

    public function create(Driver $driver, string $rawText): Order
    {
        $parsed = $this->parser->parse($rawText);
        $branch = $driver->user?->branch_id ? Branch::query()->find($driver->user->branch_id) : Branch::query()->first();
        $lat = (float) ($driver->current_lat ?: $branch?->latitude ?: 0);
        $lng = (float) ($driver->current_lng ?: $branch?->longitude ?: 0);
        $serviceFee = $this->serviceFees->calculate(1);
        $depositJasa = max(0, (int) ($parsed['deposit_jasa'] ?? $parsed['price']));
        $basePrice = max(0, (int) $parsed['price'] - $serviceFee);

        return DB::transaction(fn (): Order => Order::query()->create([
            'order_code' => $this->codeGenerator->generateRequest($parsed['service'], $branch),
            'user_id' => $driver->user_id,
            'driver_id' => $driver->id,
            'service_id' => $parsed['service']->id,
            'branch_id' => $branch?->id,
            'service_type' => $parsed['service_type'],
            'service_code' => $parsed['service_code'],
            'pickup_address' => $parsed['pickup_address'],
            'pickup_lat' => $lat,
            'pickup_lng' => $lng,
            'destination_address' => $parsed['destination_address'],
            'destination_lat' => $lat,
            'destination_lng' => $lng,
            'distance_km' => 0,
            'direction_bearing' => $this->multiOrder->calculateBearing($lat, $lng, $lat, $lng),
            'is_multi_order' => false,
            'price' => $basePrice,
            'service_charge' => $serviceFee,
            'extra_charge' => 0,
            'stops' => 1,
            'total_price' => $parsed['price'],
            'source' => 'driver_request',
            'pricing_breakdown' => [
                'source' => 'driver_request',
                'tarif' => $basePrice,
                'price' => $basePrice,
                'service_fee' => $serviceFee,
                'service_charge' => $serviceFee,
                'total_price' => (int) $parsed['price'],
                'final_price' => (int) $parsed['price'],
                'request_accepted_jasa' => (int) $parsed['price'],
                'request_deposit_jasa' => $depositJasa,
                'deposit_base' => $depositJasa,
            ],
            'raw_text' => $rawText,
            'notes' => $parsed['notes'],
            'status' => OrderStatus::Completed,
        ]))->fresh(['service', 'driver.user']);
    }
}
