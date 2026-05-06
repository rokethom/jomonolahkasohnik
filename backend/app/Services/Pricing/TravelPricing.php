<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

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
        if ($distance <= 5) {
            return 6000;
        }

        if ($distance <= 10) {
            return 12000;
        }

        return max(0, (int) ceil(($distance * 1900) - 7000));
    }
}
