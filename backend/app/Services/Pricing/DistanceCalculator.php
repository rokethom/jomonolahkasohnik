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
    private const GOOGLE_TTL_SECONDS = 3600;

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function drivingDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $key = $this->settings->get('google_maps_api_key');
        if (! filled($key)) {
            throw new RuntimeException('Google Maps API key belum aktif. Pricing membutuhkan jarak Google Maps.');
        }

        $cacheKey = sprintf('google-distance:driving:%0.5f,%0.5f:%0.5f,%0.5f', $lat1, $lng1, $lat2, $lng2);

        return Cache::remember($cacheKey, self::GOOGLE_TTL_SECONDS, function () use ($lat1, $lng1, $lat2, $lng2, $key): float {
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

            throw new RuntimeException('Jarak Google Maps tidak berhasil dihitung. Cek alamat atau API key Google Maps.');
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
