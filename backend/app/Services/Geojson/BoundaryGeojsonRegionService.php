<?php

namespace App\Services\Geojson;

use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Services\GeocodingService;
use App\Services\Spatial\GeojsonRegionLookupService;
use RuntimeException;

class BoundaryGeojsonRegionService
{
    public function __construct(
        private readonly GeocodingService $geocoding,
        private readonly GeojsonParserService $parser,
        private readonly GeojsonRegionLookupService $geojsonRegions,
    ) {
    }

    public function hasBoundaryInput(array $data): bool
    {
        return collect($this->boundaryKeys())
            ->contains(fn (string $key): bool => trim((string) ($data[$key] ?? '')) !== '');
    }

    public function generateRows(array $data): array
    {
        $missing = collect($this->boundaryKeys())
            ->filter(fn (string $key): bool => trim((string) ($data[$key] ?? '')) === '')
            ->values()
            ->all();

        if ($missing !== []) {
            throw new RuntimeException('Lengkapi batas utara, selatan, barat, dan timur untuk generate polygon otomatis.');
        }

        $branch = isset($data['branch_id']) && $data['branch_id'] !== null
            ? Branch::query()->find((int) $data['branch_id'])
            : null;

        $points = [
            'north' => $this->geocodeBoundary((string) $data['boundary_north'], $branch),
            'south' => $this->geocodeBoundary((string) $data['boundary_south'], $branch),
            'west' => $this->geocodeBoundary((string) $data['boundary_west'], $branch),
            'east' => $this->geocodeBoundary((string) $data['boundary_east'], $branch),
        ];

        $northLat = max(array_column($points, 'lat'));
        $southLat = min(array_column($points, 'lat'));
        $westLng = min(array_column($points, 'lng'));
        $eastLng = max(array_column($points, 'lng'));

        if ($northLat <= $southLat || $eastLng <= $westLng) {
            throw new RuntimeException('Batas tidak membentuk area polygon yang valid.');
        }

        $polygon = [
            ['lat' => $northLat, 'lng' => $westLng],
            ['lat' => $northLat, 'lng' => $eastLng],
            ['lat' => $southLat, 'lng' => $eastLng],
            ['lat' => $southLat, 'lng' => $westLng],
            ['lat' => $northLat, 'lng' => $westLng],
        ];

        $boundaryFeature = $this->boundaryFeature($data, $polygon, $points);
        $boundaryParsed = [
            'name' => trim((string) ($data['name'] ?? 'Batas polygon')),
            ...$this->parser->parse($boundaryFeature),
        ];

        $contained = $this->containedRegions($polygon, $branch, $southLat, $northLat, $westLng, $eastLng);

        if ($contained->isEmpty()) {
            return [$boundaryParsed];
        }

        return $contained
            ->map(fn (GeojsonRegion $region): array => [
                'branch_id' => $region->branch_id,
                'area_id' => $region->area_id,
                'name' => $region->name,
                'geojson' => $region->geojson,
                'geometry_type' => $region->geometry_type,
                'coordinates' => $region->coordinates,
                'centroid_lat' => (float) $region->centroid_lat,
                'centroid_lng' => (float) $region->centroid_lng,
                'min_lat' => (float) $region->min_lat,
                'max_lat' => (float) $region->max_lat,
                'min_lng' => (float) $region->min_lng,
                'max_lng' => (float) $region->max_lng,
            ])
            ->prepend($boundaryParsed)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function boundaryKeys(): array
    {
        return ['boundary_north', 'boundary_south', 'boundary_west', 'boundary_east'];
    }

    /**
     * @return array{lat: float, lng: float, query: string, formatted_address: string}
     */
    private function geocodeBoundary(string $address, ?Branch $branch): array
    {
        $result = $this->geojsonRegions->geocodeByName($address, $branch?->id)
            ?? $this->geocoding->geocodeNearBranchLimited($address, $branch, 6, 120)
            ?? $this->geocoding->geocodeNearBranchLimited($address, $branch, 6, null)
            ?? throw new RuntimeException('Batas tidak ditemukan di Maps atau Master Data GeoJSON: '.$address.'. Coba isi nama lebih lengkap, misalnya tambah kecamatan/kabupaten.');

        return [
            'lat' => (float) $result['lat'],
            'lng' => (float) $result['lng'],
            'query' => (string) ($result['query'] ?? $address),
            'formatted_address' => (string) ($result['formatted_address'] ?? $address),
        ];
    }

    private function boundaryFeature(array $data, array $polygon, array $points): array
    {
        return [
            'type' => 'Feature',
            'properties' => [
                'name' => trim((string) ($data['name'] ?? 'Batas polygon')),
                'source' => 'boundary_generator',
                'boundaries' => [
                    'north' => $points['north'],
                    'south' => $points['south'],
                    'west' => $points['west'],
                    'east' => $points['east'],
                ],
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$polygon[0]['lng'], $polygon[0]['lat']],
                    [$polygon[1]['lng'], $polygon[1]['lat']],
                    [$polygon[2]['lng'], $polygon[2]['lat']],
                    [$polygon[3]['lng'], $polygon[3]['lat']],
                    [$polygon[4]['lng'], $polygon[4]['lat']],
                ]],
            ],
        ];
    }

    private function containedRegions(array $polygon, ?Branch $branch, float $southLat, float $northLat, float $westLng, float $eastLng)
    {
        return GeojsonRegion::query()
            ->active()
            ->whereNotNull('centroid_lat')
            ->whereNotNull('centroid_lng')
            ->whereBetween('centroid_lat', [$southLat, $northLat])
            ->whereBetween('centroid_lng', [$westLng, $eastLng])
            ->when($branch, fn ($query) => $query->where(fn ($query) => $query->where('branch_id', $branch->id)->orWhereNull('branch_id')))
            ->get()
            ->filter(fn (GeojsonRegion $region): bool => $this->contains((float) $region->centroid_lat, (float) $region->centroid_lng, $polygon))
            ->values();
    }

    private function contains(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $polygon[$i]['lng'];
            $yi = (float) $polygon[$i]['lat'];
            $xj = (float) $polygon[$j]['lng'];
            $yj = (float) $polygon[$j]['lat'];

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 0.0000001)) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
