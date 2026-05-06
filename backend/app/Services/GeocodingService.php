<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeocodingService
{
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function geocode(string $textAddress): array
    {
        $query = trim($textAddress);
        if (mb_strlen($query) < 3) {
            throw new RuntimeException('Alamat terlalu pendek.');
        }

        return Cache::remember($this->cacheKey($query), self::TTL_SECONDS, function () use ($query): array {
            return $this->geocodeWithGoogle($query)
                ?? $this->geocodeWithMapbox($query)
                ?? $this->geocodeWithNominatim($query)
                ?? throw new RuntimeException('Alamat tidak ditemukan.');
        });
    }

    public function getAddressFromLatLng(float $lat, float $lng): array
    {
        $key = sprintf('reverse-geocode:%0.8f:%0.8f', $lat, $lng);

        return Cache::remember($key, self::TTL_SECONDS, function () use ($lat, $lng): array {
            return rescue(fn () => $this->reverseWithGoogle($lat, $lng), null, false)
                ?? rescue(fn () => $this->reverseWithMapbox($lat, $lng), null, false)
                ?? rescue(fn () => $this->reverseWithNominatim($lat, $lng), null, false)
                ?? [
                    'formatted_address' => trim($lat.', '.$lng),
                    'road' => null,
                    'district' => null,
                    'city' => null,
                    'provider' => 'coordinates',
                ];
        });
    }

    private function geocodeWithGoogle(string $query): ?array
    {
        $key = $this->settings->get('google_maps_api_key');
        if (! filled($key)) {
            return null;
        }

        $response = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $query,
            'key' => $key,
            'region' => 'id',
            'language' => 'id',
        ]);

        if (! $response->successful() || data_get($response->json(), 'status') !== 'OK') {
            return null;
        }

        $result = data_get($response->json(), 'results.0');
        $lat = data_get($result, 'geometry.location.lat');
        $lng = data_get($result, 'geometry.location.lng');

        return is_numeric($lat) && is_numeric($lng) ? [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => data_get($result, 'formatted_address', $query),
            'provider' => 'google',
        ] : null;
    }

    private function geocodeWithMapbox(string $query): ?array
    {
        $key = $this->settings->get('mapbox_api_key');
        if (! filled($key)) {
            return null;
        }

        $response = Http::timeout(6)->get('https://api.mapbox.com/geocoding/v5/mapbox.places/'.rawurlencode($query).'.json', [
            'access_token' => $key,
            'country' => 'id',
            'language' => 'id',
            'limit' => 1,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $feature = data_get($response->json(), 'features.0');
        $center = data_get($feature, 'center', []);

        return isset($center[0], $center[1]) ? [
            'lat' => (float) $center[1],
            'lng' => (float) $center[0],
            'formatted_address' => data_get($feature, 'place_name', $query),
            'provider' => 'mapbox',
        ] : null;
    }

    private function geocodeWithNominatim(string $query): ?array
    {
        $response = Http::timeout(8)
            ->withHeaders(['User-Agent' => config('app.name', 'Jojo App').'/1.0'])
            ->get('https://nominatim.openstreetmap.org/search', [
                'format' => 'jsonv2',
                'limit' => 1,
                'countrycodes' => 'id',
                'q' => $query,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $row = data_get($response->json(), '0');
        $lat = data_get($row, 'lat');
        $lng = data_get($row, 'lon');

        return is_numeric($lat) && is_numeric($lng) ? [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => data_get($row, 'display_name', $query),
            'provider' => 'nominatim',
        ] : null;
    }

    private function reverseWithGoogle(float $lat, float $lng): ?array
    {
        $key = $this->settings->get('google_maps_api_key');
        if (! filled($key)) {
            return null;
        }

        $response = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => $lat.','.$lng,
            'key' => $key,
            'language' => 'id',
            'region' => 'id',
        ]);

        if (! $response->successful() || data_get($response->json(), 'status') !== 'OK') {
            return null;
        }

        $result = data_get($response->json(), 'results.0');
        $components = collect(data_get($result, 'address_components', []));

        return [
            'formatted_address' => data_get($result, 'formatted_address', trim($lat.', '.$lng)),
            'road' => $this->googleComponent($components, 'route'),
            'district' => $this->googleComponent($components, 'administrative_area_level_3')
                ?? $this->googleComponent($components, 'sublocality'),
            'city' => $this->googleComponent($components, 'administrative_area_level_2')
                ?? $this->googleComponent($components, 'locality'),
            'provider' => 'google',
        ];
    }

    private function reverseWithMapbox(float $lat, float $lng): ?array
    {
        $key = $this->settings->get('mapbox_api_key');
        if (! filled($key)) {
            return null;
        }

        $response = Http::timeout(6)->get("https://api.mapbox.com/geocoding/v5/mapbox.places/{$lng},{$lat}.json", [
            'access_token' => $key,
            'country' => 'id',
            'language' => 'id',
            'limit' => 1,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $feature = data_get($response->json(), 'features.0');
        if (! $feature) {
            return null;
        }

        $context = collect(data_get($feature, 'context', []));

        return [
            'formatted_address' => data_get($feature, 'place_name', trim($lat.', '.$lng)),
            'road' => data_get($feature, 'text'),
            'district' => data_get($context->first(fn ($row) => str_starts_with((string) data_get($row, 'id'), 'district')), 'text'),
            'city' => data_get($context->first(fn ($row) => str_starts_with((string) data_get($row, 'id'), 'place')), 'text'),
            'provider' => 'mapbox',
        ];
    }

    private function reverseWithNominatim(float $lat, float $lng): ?array
    {
        $response = Http::timeout(8)
            ->withHeaders(['User-Agent' => config('app.name', 'Jojo App').'/1.0'])
            ->get('https://nominatim.openstreetmap.org/reverse', [
                'format' => 'jsonv2',
                'lat' => $lat,
                'lon' => $lng,
                'zoom' => 18,
                'addressdetails' => 1,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $address = data_get($response->json(), 'address', []);

        return [
            'formatted_address' => data_get($response->json(), 'display_name', trim($lat.', '.$lng)),
            'road' => data_get($address, 'road') ?? data_get($address, 'neighbourhood'),
            'district' => data_get($address, 'suburb') ?? data_get($address, 'village') ?? data_get($address, 'town'),
            'city' => data_get($address, 'city') ?? data_get($address, 'county') ?? data_get($address, 'state_district'),
            'provider' => 'nominatim',
        ];
    }

    private function googleComponent($components, string $type): ?string
    {
        return data_get($components->first(fn ($component) => in_array($type, data_get($component, 'types', []), true)), 'long_name');
    }

    private function cacheKey(string $query): string
    {
        return 'geocode:'.sha1(mb_strtolower($query));
    }
}
