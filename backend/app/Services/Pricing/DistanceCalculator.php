<?php

namespace App\Services\Pricing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DistanceCalculator
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const OSRM_TTL_SECONDS = 3600;

    public function drivingDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $cacheKey = sprintf('osrm:distance:%0.5f,%0.5f:%0.5f,%0.5f', $lat1, $lng1, $lat2, $lng2);

        return Cache::remember($cacheKey, self::OSRM_TTL_SECONDS, function () use ($lat1, $lng1, $lat2, $lng2): float {
            try {
                $url = sprintf(
                    'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F',
                    $lng1,
                    $lat1,
                    $lng2,
                    $lat2,
                );

                $response = Http::timeout(5)->retry(1, 250)->get($url, [
                    'overview' => 'false',
                    'alternatives' => 'false',
                ]);

                if ($response->successful()) {
                    $distanceMeters = data_get($response->json(), 'routes.0.distance');
                    if (is_numeric($distanceMeters)) {
                        return round(((float) $distanceMeters) / 1000, 2);
                    }
                }

                Log::warning('pricing.osrm_failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            } catch (\Throwable $exception) {
                Log::warning('pricing.osrm_exception', ['message' => $exception->getMessage()]);
            }

            Log::info('pricing.fallback_haversine', compact('lat1', 'lng1', 'lat2', 'lng2'));

            return round($this->haversine($lat1, $lng1, $lat2, $lng2), 2);
        });
    }

    public function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDistance = deg2rad($lat2 - $lat1);
        $lngDistance = deg2rad($lng2 - $lng1);

        $a = sin($latDistance / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDistance / 2) ** 2;

        return self::EARTH_RADIUS_KM * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
