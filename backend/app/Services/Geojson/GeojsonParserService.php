<?php

declare(strict_types=1);

namespace App\Services\Geojson;

use InvalidArgumentException;

class GeojsonParserService
{
    public function parse(array|string $geojson): array
    {
        $data = is_array($geojson) ? $geojson : json_decode($geojson, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('GeoJSON tidak valid.');
        }

        $geometry = $this->extractGeometry($data);
        $type = (string) ($geometry['type'] ?? '');
        if (! in_array($type, ['Polygon', 'MultiPolygon'], true)) {
            throw new InvalidArgumentException('GeoJSON hanya mendukung Polygon atau MultiPolygon.');
        }

        $polygons = $this->normalizePolygons($type, $geometry['coordinates'] ?? []);
        if ($polygons === []) {
            throw new InvalidArgumentException('GeoJSON tidak memiliki koordinat polygon yang valid.');
        }

        $flat = collect($polygons)->flatten(1)->values();
        $latitudes = $flat->pluck('lat')->all();
        $longitudes = $flat->pluck('lng')->all();

        return [
            'geojson' => $data,
            'geometry_type' => $type,
            'coordinates' => $polygons,
            'centroid_lat' => round(array_sum($latitudes) / max(count($latitudes), 1), 8),
            'centroid_lng' => round(array_sum($longitudes) / max(count($longitudes), 1), 8),
            'min_lat' => round(min($latitudes), 8),
            'max_lat' => round(max($latitudes), 8),
            'min_lng' => round(min($longitudes), 8),
            'max_lng' => round(max($longitudes), 8),
        ];
    }

    private function extractGeometry(array $data): array
    {
        if (($data['type'] ?? null) === 'FeatureCollection') {
            foreach ($data['features'] ?? [] as $feature) {
                if (is_array($feature) && isset($feature['geometry'])) {
                    return $this->extractGeometry($feature);
                }
            }
        }

        if (($data['type'] ?? null) === 'Feature') {
            return is_array($data['geometry'] ?? null) ? $data['geometry'] : [];
        }

        return $data;
    }

    private function normalizePolygons(string $type, mixed $coordinates): array
    {
        $rings = $type === 'Polygon'
            ? [$coordinates[0] ?? []]
            : collect($coordinates)->map(fn (mixed $polygon): mixed => $polygon[0] ?? [])->all();

        return collect($rings)
            ->map(fn (mixed $ring): array => collect(is_array($ring) ? $ring : [])
                ->map(function (mixed $point): ?array {
                    if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                        return null;
                    }

                    return ['lat' => round((float) $point[1], 8), 'lng' => round((float) $point[0], 8)];
                })
                ->filter()
                ->values()
                ->all())
            ->filter(fn (array $ring): bool => count($ring) >= 3)
            ->values()
            ->all();
    }
}
