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

    public function geocode(string $textAddress, array $context = []): array
    {
        $query = trim($textAddress);
        if (mb_strlen($query) < 3) {
            throw new RuntimeException('Alamat terlalu pendek.');
        }

        return Cache::remember($this->cacheKey($query, $context), self::TTL_SECONDS, function () use ($query, $context): array {
            return $this->geocodeWithGooglePlaces($query, $context)
                ?? $this->geocodeWithGoogle($query, $context)
                ?? $this->geocodeWithMapbox($query, $context)
                ?? $this->geocodeWithNominatim($query, $context)
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

    private function geocodeWithGooglePlaces(string $query, array $context = []): ?array
    {
        $key = $this->settings->get('google_maps_api_key');
        if (! filled($key)) {
            return null;
        }

        $params = [
            'query' => $query,
            'key' => $key,
            'region' => 'id',
            'language' => 'id',
        ];

        if ($this->hasBias($context)) {
            $params['location'] = $context['lat'].','.$context['lng'];
            $params['radius'] = (int) min(max(($context['radius_km'] ?? 20) * 1000, 3000), 50000);
        }

        $response = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/place/textsearch/json', $params);

        if (! $response->successful() || ! in_array(data_get($response->json(), 'status'), ['OK', 'ZERO_RESULTS'], true)) {
            return null;
        }

        $result = $this->bestCandidate(collect(data_get($response->json(), 'results', []))
            ->map(fn ($row): ?array => $this->googlePlaceCandidate($row, $query, $context))
            ->filter()
            ->values()
            ->all(), $query, $context);

        return $result ? [
            ...$result,
            'provider' => 'google_places',
        ] : null;
    }

    private function geocodeWithGoogle(string $query, array $context = []): ?array
    {
        $key = $this->settings->get('google_maps_api_key');
        if (! filled($key)) {
            return null;
        }

        $params = [
            'address' => $query,
            'key' => $key,
            'region' => 'id',
            'language' => 'id',
            'components' => 'country:ID',
        ];

        if ($bounds = $this->bounds($context)) {
            $params['bounds'] = $bounds;
        }

        $response = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', $params);

        if (! $response->successful() || data_get($response->json(), 'status') !== 'OK') {
            return null;
        }

        $result = $this->bestCandidate(collect(data_get($response->json(), 'results', []))
            ->map(fn ($row): ?array => $this->googleGeocodeCandidate($row, $query, $context))
            ->filter()
            ->values()
            ->all(), $query, $context);

        return $result ? [
            ...$result,
            'provider' => 'google',
        ] : null;
    }

    private function geocodeWithMapbox(string $query, array $context = []): ?array
    {
        $key = $this->settings->get('mapbox_api_key');
        if (! filled($key)) {
            return null;
        }

        $params = [
            'access_token' => $key,
            'country' => 'id',
            'language' => 'id',
            'limit' => 5,
            'types' => 'poi,address,place,locality,neighborhood',
        ];

        if ($this->hasBias($context)) {
            $params['proximity'] = $context['lng'].','.$context['lat'];
        }

        if ($bbox = $this->bbox($context)) {
            $params['bbox'] = $bbox;
        }

        $response = Http::timeout(6)->get('https://api.mapbox.com/geocoding/v5/mapbox.places/'.rawurlencode($query).'.json', $params);

        if (! $response->successful()) {
            return null;
        }

        $result = $this->bestCandidate(collect(data_get($response->json(), 'features', []))
            ->map(fn ($feature): ?array => $this->mapboxCandidate($feature, $query, $context))
            ->filter()
            ->values()
            ->all(), $query, $context);

        return $result ? [
            ...$result,
            'provider' => 'mapbox',
        ] : null;
    }

    private function geocodeWithNominatim(string $query, array $context = []): ?array
    {
        $params = [
            'format' => 'jsonv2',
            'limit' => 5,
            'countrycodes' => 'id',
            'q' => $query,
        ];

        if ($bbox = $this->viewbox($context)) {
            $params['viewbox'] = $bbox;
            $params['bounded'] = 0;
        }

        $response = Http::timeout(8)
            ->withHeaders(['User-Agent' => config('app.name', 'Jojo App').'/1.0'])
            ->get('https://nominatim.openstreetmap.org/search', $params);

        if (! $response->successful()) {
            return null;
        }

        $result = $this->bestCandidate(collect($response->json())
            ->map(fn ($row): ?array => $this->nominatimCandidate($row, $query, $context))
            ->filter()
            ->values()
            ->all(), $query, $context);

        return $result ? [
            ...$result,
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

    private function googlePlaceCandidate(array $row, string $query, array $context): ?array
    {
        $lat = data_get($row, 'geometry.location.lat');
        $lng = data_get($row, 'geometry.location.lng');

        return is_numeric($lat) && is_numeric($lng) ? $this->candidate([
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => trim(implode(', ', array_filter([
                data_get($row, 'name'),
                data_get($row, 'formatted_address'),
            ]))) ?: $query,
            'provider_score' => 40,
            'match_text' => trim(data_get($row, 'name').' '.data_get($row, 'formatted_address')),
        ], $query, $context) : null;
    }

    private function googleGeocodeCandidate(array $row, string $query, array $context): ?array
    {
        $lat = data_get($row, 'geometry.location.lat');
        $lng = data_get($row, 'geometry.location.lng');

        return is_numeric($lat) && is_numeric($lng) ? $this->candidate([
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => data_get($row, 'formatted_address', $query),
            'provider_score' => 30,
            'match_text' => data_get($row, 'formatted_address', ''),
        ], $query, $context) : null;
    }

    private function mapboxCandidate(array $feature, string $query, array $context): ?array
    {
        $center = data_get($feature, 'center', []);

        return isset($center[0], $center[1]) ? $this->candidate([
            'lat' => (float) $center[1],
            'lng' => (float) $center[0],
            'formatted_address' => data_get($feature, 'place_name', $query),
            'provider_score' => 25,
            'match_text' => trim(data_get($feature, 'text').' '.data_get($feature, 'place_name')),
        ], $query, $context) : null;
    }

    private function nominatimCandidate(array $row, string $query, array $context): ?array
    {
        $lat = data_get($row, 'lat');
        $lng = data_get($row, 'lon');

        return is_numeric($lat) && is_numeric($lng) ? $this->candidate([
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => data_get($row, 'display_name', $query),
            'provider_score' => 10,
            'match_text' => data_get($row, 'display_name', ''),
        ], $query, $context) : null;
    }

    private function candidate(array $candidate, string $query, array $context): array
    {
        $distance = $this->distanceFromContext((float) $candidate['lat'], (float) $candidate['lng'], $context);
        $score = (int) $candidate['provider_score'] + $this->tokenScore($query, (string) ($candidate['match_text'] ?? $candidate['formatted_address']));

        if ($distance !== null) {
            $score += max(0, 30 - (int) round($distance * 2));
            $candidate['distance_from_bias_km'] = round($distance, 3);
        }

        unset($candidate['provider_score'], $candidate['match_text']);

        return [
            ...$candidate,
            'confidence' => min(100, $score),
        ];
    }

    private function bestCandidate(array $candidates, string $query, array $context): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));

        return $candidates[0];
    }

    private function tokenScore(string $query, string $text): int
    {
        $text = mb_strtolower($text);

        return collect(preg_split('/\s+/u', mb_strtolower($query)) ?: [])
            ->map(fn (string $token): string => trim($token, " \t\n\r\0\x0B,.-"))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, ['jalan', 'jl', 'jln', 'blok', 'desa', 'kecamatan', 'kabupaten', 'indonesia'], true))
            ->unique()
            ->sum(fn (string $token): int => str_contains($text, $token) ? 5 : 0);
    }

    private function hasBias(array $context): bool
    {
        return is_numeric($context['lat'] ?? null) && is_numeric($context['lng'] ?? null);
    }

    private function distanceFromContext(float $lat, float $lng, array $context): ?float
    {
        if (! $this->hasBias($context)) {
            return null;
        }

        return $this->haversine($lat, $lng, (float) $context['lat'], (float) $context['lng']);
    }

    private function bounds(array $context): ?string
    {
        $box = $this->box($context);

        return $box ? $box['south'].','.$box['west'].'|'.$box['north'].','.$box['east'] : null;
    }

    private function bbox(array $context): ?string
    {
        $box = $this->box($context);

        return $box ? $box['west'].','.$box['south'].','.$box['east'].','.$box['north'] : null;
    }

    private function viewbox(array $context): ?string
    {
        $box = $this->box($context);

        return $box ? $box['west'].','.$box['north'].','.$box['east'].','.$box['south'] : null;
    }

    private function box(array $context): ?array
    {
        if (! $this->hasBias($context)) {
            return null;
        }

        $lat = (float) $context['lat'];
        $lng = (float) $context['lng'];
        $radiusKm = min(max((float) ($context['radius_km'] ?? 20), 5), 50);
        $latDelta = $radiusKm / 111.32;
        $lngDelta = $radiusKm / max(1, 111.32 * cos(deg2rad($lat)));

        return [
            'south' => round($lat - $latDelta, 6),
            'west' => round($lng - $lngDelta, 6),
            'north' => round($lat + $latDelta, 6),
            'east' => round($lng + $lngDelta, 6),
        ];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function cacheKey(string $query, array $context = []): string
    {
        return 'geocode:v2:'.sha1(mb_strtolower($query).'|'.json_encode([
            'lat' => isset($context['lat']) ? round((float) $context['lat'], 4) : null,
            'lng' => isset($context['lng']) ? round((float) $context['lng'], 4) : null,
            'radius_km' => isset($context['radius_km']) ? round((float) $context['radius_km']) : null,
        ]));
    }
}
