<?php

namespace App\Services\Pricing;

use App\Models\PriceSetting;
use InvalidArgumentException;
use RuntimeException;

class TravelPricing
{
    private const FREE_PICKUP_RADIUS_KM = 5.0;

    private const ROUTE_PRICES = [
        'ASB-STB' => 55000,
        'ASB-BWS' => 80000,
        'ASB-JBR' => 105000,
        'STB-BWS' => 55000,
        'STB-JBR' => 80000,
        'BWS-JBR' => 55000,
    ];

    public function __construct(private readonly ServiceFeeCalculator $serviceFeeCalculator)
    {
    }

    public function calculate(string|array $route, float $pickupDistance = 0): array
    {
        $routeKey = $this->normalizeRoute($route);
        $routeTarif = self::ROUTE_PRICES[$routeKey] ?? null;

        if ($routeTarif === null) {
            throw new InvalidArgumentException('Rute travel tidak valid.');
        }

        $pickupChargeDistance = max(0, $pickupDistance - self::FREE_PICKUP_RADIUS_KM);
        $pickupCharge = $pickupChargeDistance > 0 ? $this->generalDistanceTarif($pickupChargeDistance) : 0;

        return [
            'route' => $routeKey,
            'tarif' => $routeTarif + $pickupCharge,
            'route_tarif' => $routeTarif,
            'pickup_distance' => $pickupDistance,
            'pickup_charge' => $pickupCharge,
        ];
    }

    public function normalizeRoute(string|array $route): string
    {
        if (is_array($route)) {
            $origin = strtoupper((string) ($route['origin'] ?? $route['from'] ?? ''));
            $destination = strtoupper((string) ($route['destination'] ?? $route['to'] ?? ''));
            $route = $origin.'-'.$destination;
        }

        $route = strtoupper(str_replace([' ', '_', '→'], ['-', '-', '-'], (string) $route));
        $route = preg_replace('/-+/', '-', $route) ?: '';

        if (isset(self::ROUTE_PRICES[$route])) {
            return $route;
        }

        $parts = explode('-', $route);
        if (count($parts) === 2) {
            $reversed = $parts[1].'-'.$parts[0];
            if (isset(self::ROUTE_PRICES[$reversed])) {
                return $reversed;
            }
        }

        return $route;
    }

    private function generalDistanceTarif(float $distance): int
    {
        $distance = max(0.0, $distance);
        $setting = PriceSetting::query()
            ->active()
            ->whereNull('branch_id')
            ->forDistance($distance)
            ->orderByRaw('max_km IS NULL')
            ->orderByDesc('min_km')
            ->first();

        if (! $setting) {
            throw new RuntimeException('Distance Price Settings aktif belum tersedia untuk jarak pickup travel '.round($distance, 2).' KM.');
        }

        if (! $setting->is_formula) {
            if ($setting->price === null) {
                throw new RuntimeException('Price setting requires price when is_formula is false.');
            }

            return (int) $setting->price;
        }

        if ($setting->per_km_rate === null) {
            throw new RuntimeException('Price setting requires per_km_rate when is_formula is true.');
        }

        $price = ($distance * (int) $setting->per_km_rate) - (int) $setting->subtract_value;

        return max(0, (int) ceil($price));
    }
}
