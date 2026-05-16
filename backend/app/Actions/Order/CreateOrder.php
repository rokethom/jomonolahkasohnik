<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Models\Driver;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderCodeGenerator;
use App\Services\MultiOrderService;
use App\Services\GeocodingService;
use App\Services\LocationValidationService;
use App\Services\OrderService;
use App\Services\OrderCrewDecisionService;
use App\Services\PricingService;
use App\Services\SettingService;
use App\Services\NotificationService;
use App\Services\DriverFinanceService;
use App\Services\DriverDailyPriorityService;
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
        private readonly OrderCrewDecisionService $crewDecisions,
        private readonly LocationValidationService $locations,
        private readonly SettingService $settings,
        private readonly NotificationService $notifications,
        private readonly DriverFinanceService $finance,
        private readonly DriverDailyPriorityService $dailyPriority,
    ) {
    }

    public function handle(User $user, array $payload): Order
    {
        return DB::transaction(function () use ($user, $payload): Order {
            $this->orders->assertCustomerCanCreate($user, (string) ($payload['service_type'] ?? 'ojek'));
            $payload = $this->hydrateHiddenLocations($user, $payload);
            $payload['branch_id'] = $this->orders->resolveTargetBranchId($payload, $user->branch_id);
            $pricing = $this->pricingService->calculate($payload);
            $crewDecision = is_array($pricing['crew_decision'] ?? null)
                ? $pricing['crew_decision']
                : $this->crewDecisions->decide($payload);
            if ($crewDecision !== null) {
                if (! isset($pricing['crew_helper_fee'])) {
                    $pricing = $this->crewDecisions->applyHelperPricingToQuote($pricing, $crewDecision);
                }
                $crewDecision = $pricing['crew_decision'];
                $pricing['crew_decision'] = $crewDecision;
                $payload['notes'] = trim((string) ($payload['notes'] ?? '')."\nCrew: {$crewDecision['rule_name']} ({$crewDecision['helper_label']})");
            }
            if ($this->isTartHelperOrder($payload)) {
                $pricing['service_fee'] = 0;
                $pricing['service_charge'] = 0;
                $pricing['service_fee_breakdown'] = [];
                $pricing['final_price'] = (int) ($pricing['tarif'] ?? 0) + (int) ($pricing['extra_charge'] ?? 0);
                $payload['notes'] = trim((string) ($payload['notes'] ?? '')."\nFlag: helper kue tart tanpa service charge.");
            }
            $service = $this->resolveService((string) ($payload['service_type'] ?? 'ojek'));
            $branch = $payload['branch_id'] ? Branch::query()->find($payload['branch_id']) : $user->branch;
            $payload['pickup_address'] = str($payload['pickup_address'])->limit(250, '')->toString();
            $payload['destination_address'] = str($payload['destination_address'])->limit(250, '')->toString();
            $payment = $this->paymentPayload((string) ($payload['payment_method'] ?? 'cash'));
            $preferredVehicle = $this->preferredVehicleType($payload);
            $vehicleSeatRows = $preferredVehicle === 'mobil' ? $this->vehicleSeatRows($payload) : null;
            $driverPreference = $this->driverPreference($payload);
            if ($preferredVehicle) {
                $payload['notes'] = trim((string) ($payload['notes'] ?? '')."\nKendaraan diminta: ".($preferredVehicle === 'mobil' ? 'Mobil' : 'Motor'));
                $pricing['preferred_vehicle_type'] = $preferredVehicle;
            }
            if ($vehicleSeatRows) {
                $payload['notes'] = trim((string) ($payload['notes'] ?? '')."\nKapasitas mobil: {$vehicleSeatRows} baris");
                $pricing['required_vehicle_seat_rows'] = $vehicleSeatRows;
            }
            if ($driverPreference === 'ladies') {
                $payload['notes'] = trim((string) ($payload['notes'] ?? '')."\nPreferensi driver: Ladies");
                $pricing['driver_preference'] = 'ladies';
            }

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
                ...$payment,
                'user_id' => $user->id,
                'service_id' => $service?->id,
                'branch_id' => $payload['branch_id'],
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

            DB::afterCommit(fn () => $this->notifyEligibleDrivers($order->fresh(['user', 'items'])));

            return $order->fresh(['user', 'items']);
        });
    }

    private function notifyEligibleDrivers(Order $order): void
    {
        $drivers = Driver::query()
            ->with(['user', 'setting'])
            ->where('status', 'active')
            ->where('is_available', true)
            ->whereHas('user', fn ($query) => $query->where('branch_id', $order->branch_id))
            ->get()
            ->filter(function (Driver $driver) use ($order): bool {
                $deposit = $this->finance->monthlyDeposit($driver, now()->subMonth());
                if ($this->finance->depositBlocksOrders($deposit)) {
                    if ($driver->is_available) {
                        $driver->forceFill(['is_available' => false])->save();
                    }

                    return false;
                }

                $driver = $driver->fresh(['user', 'setting']);

                return (bool) data_get($this->multiOrder->canAcceptOrder($driver, $order), 'can_accept')
                    && $this->dailyPriority->canSeeOrder($driver, $order);
            });

        foreach ($drivers as $driver) {
            $this->notifications->sendToUser(
                $driver->user,
                'Order baru JOJO',
                trim(($order->order_code ?? 'Order baru').' - '.ucfirst((string) $order->service_type).' dari '.$order->pickup_address),
                [
                    'type' => 'new_order',
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'url' => '/?open=orders&order_id='.$order->id,
                ],
            );
        }
    }

    private function preferredVehicleType(array $payload): ?string
    {
        $vehicle = strtolower((string) ($payload['preferred_vehicle_type'] ?? data_get($payload, 'service_payload.preferred_vehicle_type', '')));

        return in_array($vehicle, ['motor', 'mobil'], true) ? $vehicle : null;
    }

    private function vehicleSeatRows(array $payload): int
    {
        $rows = (int) ($payload['vehicle_seat_rows'] ?? data_get($payload, 'service_payload.vehicle_seat_rows', 2));

        return in_array($rows, [2, 3], true) ? $rows : 2;
    }

    private function driverPreference(array $payload): string
    {
        $preference = strtolower((string) ($payload['driver_preference'] ?? data_get($payload, 'service_payload.driver_preference', 'general')));
        $service = strtolower((string) ($payload['service_type'] ?? ''));

        if (! in_array($service, ['ojek', 'oj'], true)) {
            return 'general';
        }

        return $preference === 'ladies' ? 'ladies' : 'general';
    }

    private function paymentPayload(string $method): array
    {
        $method = in_array($method, ['cash', 'transfer', 'qris'], true) ? $method : 'cash';
        $methods = $this->decodeSetting('payment_methods', [
            ['key' => 'cash', 'label' => 'Pembayaran Cash', 'description' => 'Customer membayar manual kepada driver.'],
            ['key' => 'transfer', 'label' => 'Pembayaran Transfer', 'description' => 'Customer transfer ke rekening aplikasi.'],
            ['key' => 'qris', 'label' => 'Pembayaran QRIS', 'description' => 'Customer scan QRIS aplikasi.'],
        ]);
        $selected = collect($methods)->firstWhere('key', $method) ?: [
            'key' => $method,
            'label' => $method === 'qris' ? 'Pembayaran QRIS' : $method,
        ];

        return [
            'payment_method' => $method,
            'payment_label' => $selected['label'] ?? $method,
            'payment_meta' => in_array($method, ['transfer', 'qris'], true)
                ? [
                    'transfer_accounts' => $method === 'transfer' ? $this->transferAccounts() : [],
                    'qris_image' => $method === 'qris' ? $this->settings->get('payment_qris_image') : null,
                ]
                : null,
        ];
    }

    private function transferAccounts(): array
    {
        $raw = $this->decodeSetting('payment_transfer_account', []);
        $accounts = array_is_list($raw) ? $raw : [$raw];

        return collect($accounts)
            ->filter(fn (mixed $account): bool => is_array($account))
            ->map(fn (array $account): array => [
                'bank' => trim((string) ($account['bank'] ?? '')),
                'account_name' => trim((string) ($account['account_name'] ?? '')),
                'account_number' => trim((string) ($account['account_number'] ?? '')),
            ])
            ->filter(fn (array $account): bool => $account['bank'] !== '' || $account['account_name'] !== '' || $account['account_number'] !== '')
            ->values()
            ->all();
    }

    private function decodeSetting(string $key, array $default): array
    {
        $value = $this->settings->get($key);
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : $default;
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

    private function isTartHelperOrder(array $payload): bool
    {
        return (bool) data_get($payload, 'service_payload.tart_helper')
            || data_get($payload, 'service_payload.helper_role') === 'tart_helper';
    }

    private function hydrateHiddenLocations(User $user, array $payload): array
    {
        $branch = isset($payload['branch_id']) && $payload['branch_id']
            ? Branch::query()->find((int) $payload['branch_id'])
            : $user->branch;

        if (! isset($payload['pickup_lat'], $payload['pickup_lng'])) {
            $geo = $this->geocoding->geocodeNearBranch($payload['pickup_address'], $branch);
            $payload['pickup_lat'] = $geo['lat'];
            $payload['pickup_lng'] = $geo['lng'];
            $payload['pickup_address'] = $geo['formatted_address'] ?? $payload['pickup_address'];
            $payload['geocoded_by'] = $geo['provider'] ?? null;
        }

        if (! isset($payload['destination_lat'], $payload['destination_lng'])) {
            $geo = $this->geocoding->geocodeNearBranch($payload['destination_address'], $branch);
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
