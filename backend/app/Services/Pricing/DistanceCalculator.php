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
    private const OSRM_CONNECT_TIMEOUT_SECONDS = 3;
    private const OSRM_TIMEOUT_SECONDS = 8;

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function drivingDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return (float) $this->drivingDistanceResult($lat1, $lng1, $lat2, $lng2)['distance_km'];
    }

    public function drivingDistanceResult(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $cacheKey = sprintf('route-distance:driving:%0.5f,%0.5f:%0.5f,%0.5f', $lat1, $lng1, $lat2, $lng2);
        $baseUrl = rtrim((string) $this->settings->get('osrm_base_url', 'https://router.project-osrm.org'), '/');
        $cacheKey .= ':'.sha1(json_encode([
            'osrm_active' => $this->settings->bool('osrm_active', true),
            'osrm_base_url' => $baseUrl,
            'google_distance' => $this->settings->bool('google_maps_distance_enabled', false),
        ]));

        return Cache::remember($cacheKey, self::ROUTE_TTL_SECONDS, function () use ($lat1, $lng1, $lat2, $lng2): array {
            $osrm = $this->osrmDistance($lat1, $lng1, $lat2, $lng2);
            if ($osrm !== null) {
                return [
                    'distance_km' => $osrm,
                    'provider' => 'osrm',
                    'fallback_used' => false,
                ];
            }

            if ($this->settings->bool('google_maps_distance_enabled', false)) {
                $google = $this->googleDistance($lat1, $lng1, $lat2, $lng2);
                if ($google !== null) {
                    return [
                        'distance_km' => $google,
                        'provider' => 'google_maps',
                        'fallback_used' => true,
                    ];
                }
            }

            throw new RuntimeException('Jarak rute tidak berhasil dihitung dari Google Maps maupun OSRM. Cek alamat atau koneksi API.');
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
            $response = Http::connectTimeout(1)->timeout(3)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
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
                    Log::info('pricing.google_distance_used', compact('lat1', 'lng1', 'lat2', 'lng2'));

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
        if (! $this->settings->bool('osrm_active', true)) {
            return null;
        }

        try {
            $baseUrl = rtrim((string) $this->settings->get('osrm_base_url', 'https://router.project-osrm.org'), '/');
            $url = sprintf(
                '%s/route/v1/driving/%F,%F;%F,%F',
                $baseUrl,
                $lng1,
                $lat1,
                $lng2,
                $lat2,
            );

            $response = Http::retry(2, 250, throw: false)
                ->connectTimeout(self::OSRM_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::OSRM_TIMEOUT_SECONDS)
                ->get($url, [
                    'overview' => 'false',
                    'alternatives' => 'false',
                ]);

            if ($response->successful()) {
                $distanceMeters = data_get($response->json(), 'routes.0.distance');
                if (is_numeric($distanceMeters)) {
                    Log::info('pricing.osrm_distance_used', compact('lat1', 'lng1', 'lat2', 'lng2'));

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
