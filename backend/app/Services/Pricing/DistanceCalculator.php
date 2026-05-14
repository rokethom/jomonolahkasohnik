<?php

namespace App\Services\Pricing;

use App\Services\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DistanceCalculator
{
    private const EARTH_RADIUS_KM = 6371.0;
    private const ROUTE_TTL_SECONDS = 3600;

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function drivingDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $cacheKey = sprintf('route-distance:driving:%0.5f,%0.5f:%0.5f,%0.5f', $lat1, $lng1, $lat2, $lng2);

        return Cache::remember($cacheKey, self::ROUTE_TTL_SECONDS, function () use ($lat1, $lng1, $lat2, $lng2): float {
            return $this->googleDistance($lat1, $lng1, $lat2, $lng2)
                ?? $this->osrmDistance($lat1, $lng1, $lat2, $lng2)
                ?? throw new RuntimeException('Jarak rute tidak berhasil dihitung dari Google Maps maupun OSRM. Cek alamat atau koneksi API.');
        });
    }

    private function googleDistance(float $lat1, float $lng1, float $lat2, float $lng2): ?float
    {
        $key = $this->settings->get('google_maps_api_key');
        if (! filled($key)) {
            Log::warning('pricing.google_distance_missing_key');

            return null;
        }

        try {
            $response = Http::connectTimeout(2)->timeout(5)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $lat1.','.$lng1,
                'destinations' => $lat2.','.$lng2,
                'mode' => 'driving',
                'region' => 'id',
                'language' => 'id',
                'units' => 'metric',
                'key' => $key,
            ]);

            $status = data_get($response->json(), 'status');
            $elementStatus = data_get($response->json(), 'rows.0.elements.0.status');

            if ($response->successful() && $status === 'OK' && $elementStatus === 'OK') {
                $distanceMeters = data_get($response->json(), 'rows.0.elements.0.distance.value');
                if (is_numeric($distanceMeters)) {
                    return round(((float) $distanceMeters) / 1000, 2);
                }
            }

            Log::warning('pricing.google_distance_failed', [
                'status' => $response->status(),
                'google_status' => $status,
                'element_status' => $elementStatus,
                'body' => $response->body(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('pricing.google_distance_exception', ['message' => $exception->getMessage()]);
        }

        return null;
    }

    private function osrmDistance(float $lat1, float $lng1, float $lat2, float $lng2): ?float
    {
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F',
                $lng1,
                $lat1,
                $lng2,
                $lat2,
            );

            $response = Http::connectTimeout(2)->timeout(5)->get($url, [
                'overview' => 'false',
                'alternatives' => 'false',
            ]);

            if ($response->successful()) {
                $distanceMeters = data_get($response->json(), 'routes.0.distance');
                if (is_numeric($distanceMeters)) {
                    Log::info('pricing.osrm_distance_fallback_used', compact('lat1', 'lng1', 'lat2', 'lng2'));

                    return round(((float) $distanceMeters) / 1000, 2);
                }
            }

            Log::warning('pricing.osrm_distance_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('pricing.osrm_distance_exception', ['message' => $exception->getMessage()]);
        }

        return null;
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
