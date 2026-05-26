<?php

namespace App\Services\Pricing;

use App\Services\SettingService;

class JokerPricing
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function calculate(float $distance, array $context = []): array
    {
        $billingDistance = $this->roundDistance($distance);
        $config = $this->config();

        if ($billingDistance <= $config['ring1_max_km']) {
            $ring = 'ring_1';
            $tarif = $config['ring1_price'];
            $formula = 'fixed';
        } elseif ($billingDistance <= $config['ring2_max_km']) {
            $ring = 'ring_2';
            $tarif = ($billingDistance * $config['ring2_per_km']) + $config['ring2_add'];
            $formula = 'distance_plus';
        } else {
            $ring = 'ring_3';
            $tarif = ($billingDistance * $config['ring3_per_km']) + $config['ring3_add'];
            $formula = 'distance_plus';
        }

        $pickupCharge = $this->isPickupTariff($context)
            ? (int) ceil($config['ring1_price'] * ($config['pickup_markup_percent'] / 100))
            : 0;
        $waitCharge = $this->waitCharge($context, $config);
        $helperCharge = $this->helperCharge($context, $config);
        $nightCharge = $this->isNightTariff($context)
            ? (int) ceil(($tarif + $pickupCharge + $waitCharge + $helperCharge) * ($config['night_percent'] / 100))
            : 0;
        $tarif = $this->roundUpToThousand($tarif + $pickupCharge + $waitCharge + $helperCharge + $nightCharge);

        return [
            'distance' => $distance,
            'billing_distance' => $billingDistance,
            'ring' => $ring,
            'formula' => $formula,
            'base_tarif' => $config['ring1_price'],
            'per_km_rate' => $ring === 'ring_2' ? $config['ring2_per_km'] : ($ring === 'ring_3' ? $config['ring3_per_km'] : 0),
            'formula_add' => $ring === 'ring_2' ? $config['ring2_add'] : ($ring === 'ring_3' ? $config['ring3_add'] : 0),
            'pickup_charge' => $pickupCharge,
            'wait_charge' => $waitCharge,
            'helper_charge' => $helperCharge,
            'night_charge' => $nightCharge,
            'tarif' => $tarif,
            'config' => $config,
        ];
    }

    public function roundDistance(float $distance): int
    {
        $floor = (int) floor($distance);
        $decimal = $distance - $floor;

        return $decimal <= 0.5 ? $floor : $floor + 1;
    }

    public function depositAmount(int $jasa, float $distance): int
    {
        $config = $this->config();
        $ring1MaxJasa = (int) $config['deposit_ring1_max_jasa'];
        $ring1Amount = (int) $config['deposit_ring1_amount'];

        if ($jasa <= $ring1MaxJasa) {
            return $ring1Amount;
        }

        return $ring1Amount + (int) floor(($jasa - $ring1MaxJasa) * ($config['deposit_ring2_percent'] / 100));
    }

    /**
     * @return array<int, array{name: string, lat: float, lng: float}>
     */
    public function originPoints(): array
    {
        $value = $this->settings->get('joker_mobil_origin_points', '[]');
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn (mixed $point): bool => is_array($point) && is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null))
            ->map(fn (array $point): array => [
                'name' => trim((string) ($point['name'] ?? 'Titik hitung Joker Mobil')),
                'lat' => (float) $point['lat'],
                'lng' => (float) $point['lng'],
            ])
            ->values()
            ->all();
    }

    public function nearestOriginPoint(float $lat, float $lng, DistanceCalculator $distances): ?array
    {
        $points = $this->originPoints();
        if ($points === []) {
            return null;
        }

        $nearest = null;
        $nearestDistance = null;
        foreach ($points as $point) {
            $distance = $distances->haversine($lat, $lng, $point['lat'], $point['lng']);
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $point;
                $nearestDistance = $distance;
            }
        }

        return $nearest === null ? null : [
            ...$nearest,
            'distance_to_pickup_km' => $nearestDistance,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function config(): array
    {
        return [
            'ring1_max_km' => $this->float('joker_mobil_ring1_max_km', 3.5),
            'ring1_price' => $this->int('joker_mobil_ring1_price', 25000),
            'ring2_max_km' => $this->float('joker_mobil_ring2_max_km', 10.5),
            'ring2_per_km' => $this->int('joker_mobil_ring2_per_km', 5000),
            'ring2_add' => $this->int('joker_mobil_ring2_add', 10000),
            'ring3_per_km' => $this->int('joker_mobil_ring3_per_km', 4000),
            'ring3_add' => $this->int('joker_mobil_ring3_add', 20000),
            'pickup_markup_percent' => $this->float('joker_mobil_pickup_markup_percent', 50),
            'deposit_ring1_max_jasa' => $this->int('joker_mobil_deposit_ring1_max_jasa', 25000),
            'deposit_ring1_amount' => $this->int('joker_mobil_deposit_ring1_amount', 1000),
            'deposit_ring2_percent' => $this->float('joker_mobil_deposit_ring2_percent', 10),
            'round_trip_second_point_percent' => $this->float('joker_mobil_round_trip_second_point_percent', 50),
            'round_trip_free_wait_minutes' => $this->int('joker_mobil_round_trip_free_wait_minutes', 90),
            'wait_price_per_block' => $this->int('joker_mobil_wait_price_per_block', 5000),
            'wait_block_minutes' => $this->int('joker_mobil_wait_block_minutes', 30),
            'night_percent' => $this->float('joker_mobil_night_percent', 20),
            'helper_fee_increment' => $this->int('joker_mobil_helper_fee_increment', 5000),
        ];
    }

    private function waitCharge(array $context, array $config): int
    {
        $minutes = (int) data_get($context, 'service_payload.wait_minutes', data_get($context, 'wait_minutes', 0));
        if ($minutes <= 0) {
            return 0;
        }

        $block = max(1, (int) $config['wait_block_minutes']);

        return (int) ceil($minutes / $block) * (int) $config['wait_price_per_block'];
    }

    private function helperCharge(array $context, array $config): int
    {
        $manual = data_get($context, 'service_payload.helper_fee', data_get($context, 'helper_fee'));
        if (is_numeric($manual)) {
            return max(0, $this->roundToIncrement((int) $manual, (int) $config['helper_fee_increment']));
        }

        $units = (int) data_get($context, 'service_payload.helper_units', data_get($context, 'helper_units', 0));

        return max(0, $units * (int) $config['helper_fee_increment']);
    }

    private function isPickupTariff(array $context): bool
    {
        return filter_var(data_get($context, 'service_payload.joker_pickup', data_get($context, 'joker_pickup', false)), FILTER_VALIDATE_BOOL);
    }

    private function isNightTariff(array $context): bool
    {
        return filter_var(data_get($context, 'service_payload.joker_night_tariff', data_get($context, 'joker_night_tariff', false)), FILTER_VALIDATE_BOOL);
    }

    private function int(string $key, int $default): int
    {
        return max(0, $this->settings->int($key, $default));
    }

    private function float(string $key, float $default): float
    {
        $value = $this->settings->get($key, $default);

        return max(0.0, (float) $value);
    }

    private function roundToIncrement(int $amount, int $increment): int
    {
        $increment = max(1, $increment);

        return (int) ceil($amount / $increment) * $increment;
    }

    private function roundUpToThousand(int|float $amount): int
    {
        return (int) ceil($amount / 1000) * 1000;
    }
}
