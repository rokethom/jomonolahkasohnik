<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Collection;

class MultiOrderService
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function canAcceptOrder(Driver $driver, Order $newOrder): array
    {
        $driver->loadMissing(['setting', 'user']);
        $activeOrders = $this->activeOrders($driver);
        $activeCount = $activeOrders->count();
        $globalEnabled = $this->settings->bool('multi_order_enabled', true);
        $driverEnabled = $driver->setting?->multi_order_active ?? true;
        $maxOrders = max(1, min(3, $this->settings->int('max_multi_order', 3)));
        $areaMatch = $this->isSameArea($driver, $newOrder);
        $serviceMatch = $this->canServe($driver, $newOrder);
        $vehicleMatch = $this->canServeVehicle($driver, $newOrder);
        $ladiesMatch = $this->canServeLadiesOrder($driver, $newOrder);

        if ($driver->status !== 'active') {
            return [
                'can_accept' => false,
                'reason' => 'driver tidak aktif',
                'direction_match' => false,
                'area_match' => $areaMatch,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if (! $driver->is_available) {
            return [
                'can_accept' => false,
                'reason' => 'driver off',
                'direction_match' => false,
                'area_match' => $areaMatch,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if (! $vehicleMatch) {
            return [
                'can_accept' => false,
                'reason' => 'kendaraan tidak sesuai',
                'direction_match' => false,
                'area_match' => $areaMatch,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if (! $ladiesMatch) {
            return [
                'can_accept' => false,
                'reason' => 'khusus driver ladies',
                'direction_match' => false,
                'area_match' => $areaMatch,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if (! $serviceMatch) {
            return [
                'can_accept' => false,
                'reason' => 'layanan tidak aktif untuk driver',
                'direction_match' => false,
                'area_match' => $areaMatch,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if (! $areaMatch) {
            return [
                'can_accept' => false,
                'reason' => 'di luar area driver',
                'direction_match' => false,
                'area_match' => false,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if (! $globalEnabled || ! $driverEnabled) {
            return [
                'can_accept' => $activeCount < 1,
                'reason' => $activeCount < 1 ? null : 'multi order nonaktif',
                'direction_match' => $activeCount < 1,
                'area_match' => true,
                'active_order_count' => $activeCount,
                'max_order' => 1,
            ];
        }

        if ($activeCount >= $maxOrders) {
            return [
                'can_accept' => false,
                'reason' => 'maksimal order aktif tercapai',
                'direction_match' => false,
                'area_match' => true,
                'active_order_count' => $activeCount,
                'max_order' => $maxOrders,
            ];
        }

        if ($activeCount === 0) {
            return [
                'can_accept' => true,
                'reason' => null,
                'direction_match' => true,
                'area_match' => true,
                'active_order_count' => 0,
                'max_order' => $maxOrders,
            ];
        }

        $directionMatch = $activeOrders->every(fn (Order $activeOrder): bool => $this->isSameDirection($activeOrder, $newOrder));

        return [
            'can_accept' => $directionMatch,
            'reason' => $directionMatch ? null : 'tidak searah',
            'direction_match' => $directionMatch,
            'area_match' => true,
            'active_order_count' => $activeCount,
            'max_order' => $maxOrders,
        ];
    }

    public function isSameDirection(Order $orderA, Order $orderB, float $threshold = 45): bool
    {
        $bearingA = $this->bearingFor($orderA);
        $bearingB = $this->bearingFor($orderB);

        if ($bearingA === null || $bearingB === null) {
            return false;
        }

        return $this->angleDifference($bearingA, $bearingB) <= $threshold;
    }

    public function bearingFor(Order $order): ?float
    {
        if ($order->direction_bearing !== null) {
            return (float) $order->direction_bearing;
        }

        $bearing = $this->calculateBearing(
            (float) $order->pickup_lat,
            (float) $order->pickup_lng,
            (float) $order->destination_lat,
            (float) $order->destination_lng,
        );

        if ($order->exists) {
            $order->forceFill(['direction_bearing' => $bearing])->save();
        }

        return $bearing;
    }

    public function calculateBearing(float $startLat, float $startLng, float $endLat, float $endLng): ?float
    {
        if ($startLat === $endLat && $startLng === $endLng) {
            return null;
        }

        $lat1 = deg2rad($startLat);
        $lat2 = deg2rad($endLat);
        $deltaLng = deg2rad($endLng - $startLng);

        $y = sin($deltaLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    public function angleDifference(float $bearingA, float $bearingB): float
    {
        $difference = abs($bearingA - $bearingB);

        return min($difference, 360 - $difference);
    }

    private function activeOrders(Driver $driver): Collection
    {
        return $driver->orders()
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
            ])
            ->oldest()
            ->get();
    }

    private function isSameArea(Driver $driver, Order $order): bool
    {
        $driverBranchId = $driver->user?->branch_id;
        $orderBranchId = $order->branch_id;

        if ($driverBranchId === null || $orderBranchId === null) {
            return false;
        }

        return (int) $driverBranchId === (int) $orderBranchId;
    }

    private function canServe(Driver $driver, Order $order): bool
    {
        $allowed = $driver->allowed_service_types ?? [];
        if ($allowed === []) {
            return true;
        }

        $service = $this->normalizeService((string) $order->service_type);

        return in_array($service, array_map(fn ($item): string => $this->normalizeService((string) $item), $allowed), true);
    }

    private function canServeLadiesOrder(Driver $driver, Order $order): bool
    {
        return data_get($order->pricing_breakdown, 'driver_preference') !== 'ladies'
            || (bool) $driver->is_ladies_driver;
    }

    private function canServeVehicle(Driver $driver, Order $order): bool
    {
        $preferredVehicle = data_get($order->pricing_breakdown, 'preferred_vehicle_type');
        if (! in_array($preferredVehicle, ['motor', 'mobil'], true)) {
            return true;
        }

        if (! in_array($preferredVehicle, $driver->vehicleTypes(), true)) {
            return false;
        }

        if ($preferredVehicle !== 'mobil') {
            return true;
        }

        $requiredRows = (int) data_get($order->pricing_breakdown, 'required_vehicle_seat_rows', 2);
        $driverRows = (int) ($driver->vehicle_seat_rows ?: 2);

        return $driverRows >= max(2, min(3, $requiredRows));
    }

    private function normalizeService(string $service): string
    {
        return match (strtolower(trim($service))) {
            'do' => 'delivery',
            'gift', 'gift order' => 'gift_order',
            'joker mobil', 'joker-mobile', 'joker' => 'joker_mobil',
            default => strtolower(trim($service)),
        };
    }
}
