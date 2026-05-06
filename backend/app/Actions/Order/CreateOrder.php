<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderCodeGenerator;
use App\Services\MultiOrderService;
use App\Services\GeocodingService;
use App\Services\LocationValidationService;
use App\Services\OrderService;
use App\Services\PricingService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateOrder
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly OrderCodeGenerator $orderCodeGenerator,
        private readonly MultiOrderService $multiOrder,
        private readonly GeocodingService $geocoding,
        private readonly OrderService $orders,
        private readonly LocationValidationService $locations,
    ) {
    }

    public function handle(User $user, array $payload): Order
    {
        return DB::transaction(function () use ($user, $payload): Order {
            $this->orders->assertCustomerCanCreate($user, (string) ($payload['service_type'] ?? 'ojek'));
            $payload = $this->hydrateHiddenLocations($user, $payload);
            $pricing = $this->pricingService->calculate($payload);
            $service = $this->resolveService((string) ($payload['service_type'] ?? 'ojek'));
            $branch = $user->branch;
            $payload['pickup_address'] = str($payload['pickup_address'])->limit(250, '')->toString();
            $payload['destination_address'] = str($payload['destination_address'])->limit(250, '')->toString();

            $order = Order::create([
                ...Arr::only($payload, [
                    'service_type',
                    'pickup_address',
                    'pickup_lat',
                    'pickup_lng',
                    'destination_address',
                    'destination_lat',
                    'destination_lng',
                    'notes',
                ]),
                'user_id' => $user->id,
                'service_id' => $service?->id,
                'branch_id' => $user->branch_id,
                'service_code' => $service?->code,
                'order_code' => $this->orderCodeGenerator->generate($service?->code, $branch),
                'distance_km' => $pricing['distance'],
                'direction_bearing' => $this->multiOrder->calculateBearing(
                    (float) $payload['pickup_lat'],
                    (float) $payload['pickup_lng'],
                    (float) $payload['destination_lat'],
                    (float) $payload['destination_lng'],
                ),
                'price' => $pricing['tarif'],
                'service_charge' => $pricing['service_fee'],
                'extra_charge' => $pricing['extra_charge'],
                'stops' => $pricing['stops'],
                'total_price' => $pricing['final_price'],
                'pricing_breakdown' => $pricing,
                'geocoded_by' => $payload['geocoded_by'] ?? null,
                'locked_location_hash' => $this->orders->locationHash($payload),
                'device_location_log_id' => $payload['device_location_log_id'] ?? null,
                'expired_at' => now()->addMinutes(10),
                'status' => OrderStatus::Created,
            ]);

            foreach (array_slice($payload['points'] ?? [], 0, $this->orders->maxTextPoints()) as $index => $point) {
                $geo = $this->safeGeocode($point['address']);
                $order->points()->create([
                    'sequence' => $index + 1,
                    'label' => $point['label'] ?? 'Titik '.($index + 1),
                    'address' => $point['address'],
                    'lat' => $geo['lat'] ?? null,
                    'lng' => $geo['lng'] ?? null,
                    'geocoded_by' => $geo['provider'] ?? null,
                ]);
            }

            foreach ($payload['items'] ?? [] as $item) {
                $order->items()->create([
                    'name' => $item['name'],
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            try {
                OrderCreated::dispatch($order->fresh(['user', 'items']));
            } catch (\Throwable $exception) {
                Log::warning('broadcast.order_created_failed', [
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            return $order->fresh(['user', 'items']);
        });
    }

    private function resolveService(string $serviceType): ?Service
    {
        $code = match (strtolower($serviceType)) {
            'ojek' => 'OJ',
            'kurir' => 'KR',
            'delivery', 'do' => 'DO',
            'belanja' => 'BL',
            'gift', 'gift_order' => 'GO',
            'travel' => 'TV',
            'joker_mobil', 'joker mobil' => 'JM',
            default => strtoupper($serviceType),
        };

        return Service::query()->where('code', $code)->first();
    }

    private function hydrateHiddenLocations(User $user, array $payload): array
    {
        if (! isset($payload['pickup_lat'], $payload['pickup_lng'])) {
            $geo = $this->geocoding->geocode($payload['pickup_address']);
            $payload['pickup_lat'] = $geo['lat'];
            $payload['pickup_lng'] = $geo['lng'];
            $payload['pickup_address'] = $geo['formatted_address'] ?? $payload['pickup_address'];
            $payload['geocoded_by'] = $geo['provider'] ?? null;
        }

        if (! isset($payload['destination_lat'], $payload['destination_lng'])) {
            $geo = $this->geocoding->geocode($payload['destination_address']);
            $payload['destination_lat'] = $geo['lat'];
            $payload['destination_lng'] = $geo['lng'];
            $payload['destination_address'] = $geo['formatted_address'] ?? $payload['destination_address'];
            $payload['geocoded_by'] = $payload['geocoded_by'] ?? ($geo['provider'] ?? null);
        }

        if (isset($payload['device_location'])) {
            $device = $payload['device_location'];
            $validation = $this->locations->validateLocation(
                $user,
                (float) $device['lat'],
                (float) $device['lng'],
                isset($device['speed']) ? (float) $device['speed'] : null,
                isset($device['accuracy']) ? (float) $device['accuracy'] : null,
                $device['timestamp'] ?? null,
                [
                    'branch_id' => $user->branch_id,
                    'is_mock_location' => (bool) ($device['is_mock_location'] ?? false),
                    'provider' => 'customer_device',
                ],
            );
            $payload['device_location_log_id'] = $validation['location_log']->id;
        }

        return $payload;
    }

    private function safeGeocode(string $address): array
    {
        try {
            return $this->geocoding->geocode($address);
        } catch (\Throwable) {
            return [];
        }
    }
}
