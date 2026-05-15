<?php

namespace App\Services\Spatial;

use App\Models\GeojsonRegion;
use App\Services\AiAliasMapService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GeojsonRegionLookupService
{
    public function __construct(private readonly AiAliasMapService $aliasMaps)
    {
    }

    public function detect(float $lat, float $lng): ?GeojsonRegion
    {
        if (! Schema::hasTable('geojson_regions')) {
            return null;
        }

        $cacheKey = 'geojson-region-lookup:'.$this->cacheVersion().':'.round($lat, 4).':'.round($lng, 4);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($lat, $lng): ?GeojsonRegion {
            return GeojsonRegion::query()
                ->with(['branch', 'area'])
                ->active()
                ->containingBoundingBox($lat, $lng)
                ->orderByDesc('version')
                ->get()
                ->first(fn (GeojsonRegion $region): bool => $this->contains($lat, $lng, $region->coordinates ?? []));
        });
    }

    public function geocodeByName(string $address, ?int $branchId = null): ?array
    {
        if (! Schema::hasTable('geojson_regions')) {
            return null;
        }

        $aliasMap = $this->aliasMaps->resolve($address, $branchId);
        if ($aliasMap !== null) {
            return $this->aliasMaps->geocodeResult($aliasMap);
        }

        $needle = $this->normalize($address);
        if (mb_strlen($needle) < 3) {
            return null;
        }

        $cacheKey = 'geojson-region-name-lookup:'.$this->cacheVersion().':'.($branchId ?: 'global').':'.sha1($needle);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($needle, $branchId): ?array {
            $region = GeojsonRegion::query()
                ->with(['branch', 'area'])
                ->active()
                ->whereNotNull('centroid_lat')
                ->whereNotNull('centroid_lng')
                ->when($branchId, fn ($query) => $query->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id')))
                ->get()
                ->map(fn (GeojsonRegion $region): array => [
                    'region' => $region,
                    'score' => $this->nameScore($needle, $region),
                ])
                ->filter(fn (array $candidate): bool => $candidate['score'] > 0)
                ->sortByDesc('score')
                ->first()['region'] ?? null;

            if (! $region instanceof GeojsonRegion) {
                return null;
            }

            return [
                'lat' => (float) $region->centroid_lat,
                'lng' => (float) $region->centroid_lng,
                'formatted_address' => trim(implode(', ', array_filter([
                    $region->name,
                    $region->area?->name,
                    $region->branch?->display_name,
                ]))),
                'provider' => 'geojson_region',
                'confidence' => 95,
                'geojson_region_id' => $region->id,
                'geojson_region_name' => $region->name,
                'geojson_area_id' => $region->area_id,
                'geojson_area_name' => $region->area?->name,
                'query' => $region->name,
            ];
        });
    }

    private function contains(float $lat, float $lng, array $polygons): bool
    {
        foreach ($this->normalizePolygons($polygons) as $polygon) {
            if ($this->containsSinglePolygon($lat, $lng, $polygon)) {
                return true;
            }
        }

        return false;
    }

    /**
     * GeoJSON rows created by the current parser are stored as array-of-polygons,
     * while older manual rows may be stored as a single polygon. Support both so
     * region detection stays reliable for existing CMS data.
     *
     * @return array<int, array<int, array{lat: float, lng: float}>>
     */
    private function normalizePolygons(array $value): array
    {
        if ($value === []) {
            return [];
        }

        if ($this->isPoint($value[0] ?? null)) {
            return [$this->normalizePolygon($value)];
        }

        $polygons = [];
        foreach ($value as $polygon) {
            if (! is_array($polygon)) {
                continue;
            }

            if ($this->isPoint($polygon[0] ?? null)) {
                $normalized = $this->normalizePolygon($polygon);
                if (count($normalized) >= 3) {
                    $polygons[] = $normalized;
                }

                continue;
            }

            foreach ($polygon as $ring) {
                if (is_array($ring) && $this->isPoint($ring[0] ?? null)) {
                    $normalized = $this->normalizePolygon($ring);
                    if (count($normalized) >= 3) {
                        $polygons[] = $normalized;
                    }
                }
            }
        }

        return $polygons;
    }

    /**
     * @param array<int, mixed> $polygon
     * @return array<int, array{lat: float, lng: float}>
     */
    private function normalizePolygon(array $polygon): array
    {
        return collect($polygon)
            ->map(fn (mixed $point): ?array => $this->normalizePoint($point))
            ->filter()
            ->values()
            ->all();
    }

    private function isPoint(mixed $point): bool
    {
        if (! is_array($point)) {
            return false;
        }

        return (isset($point['lat'], $point['lng']) && is_numeric($point['lat']) && is_numeric($point['lng']))
            || (isset($point['latitude'], $point['longitude']) && is_numeric($point['latitude']) && is_numeric($point['longitude']))
            || (isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1]));
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function normalizePoint(mixed $point): ?array
    {
        if (! is_array($point)) {
            return null;
        }

        if (isset($point['lat'], $point['lng']) && is_numeric($point['lat']) && is_numeric($point['lng'])) {
            return ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
        }

        if (isset($point['latitude'], $point['longitude']) && is_numeric($point['latitude']) && is_numeric($point['longitude'])) {
            return ['lat' => (float) $point['latitude'], 'lng' => (float) $point['longitude']];
        }

        if (isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
            return ['lat' => (float) $point[1], 'lng' => (float) $point[0]];
        }

        return null;
    }

    private function containsSinglePolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($polygon[$i]['lng'] ?? 0);
            $yi = (float) ($polygon[$i]['lat'] ?? 0);
            $xj = (float) ($polygon[$j]['lng'] ?? 0);
            $yj = (float) ($polygon[$j]['lat'] ?? 0);

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 0.0000001)) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function nameScore(string $needle, GeojsonRegion $region): int
    {
        $names = collect([
            $region->name,
            $region->area?->name,
            $region->area?->code,
        ])
            ->map(fn (mixed $value): string => $this->normalize((string) $value))
            ->filter()
            ->unique()
            ->values();

        $score = 0;
        foreach ($names as $name) {
            if ($name === $needle) {
                $score = max($score, 10000 + mb_strlen($name));
                continue;
            }

            if (Str::contains($needle, $name) || Str::contains($name, $needle)) {
                $score = max($score, 5000 + mb_strlen($name));
                continue;
            }

            $tokens = collect(preg_split('/\s+/u', $needle) ?: [])
                ->filter(fn (string $token): bool => mb_strlen($token) >= 3);

            $matchedTokens = $tokens->filter(fn (string $token): bool => Str::contains($name, $token))->count();
            if ($matchedTokens > 0) {
                $score = max($score, $matchedTokens * 100);
            }
        }

        return $score;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->replaceMatches('/\b(?:ke|dari|di|depan|belakang|samping|arah|menuju)\b/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();
    }

    private function cacheVersion(): string
    {
        if (! Schema::hasTable('geojson_regions')) {
            return 'none';
        }

        $latest = GeojsonRegion::query()->max('updated_at');
        $count = GeojsonRegion::query()->count();

        return sha1((string) $latest.'|'.$count);
    }
}
