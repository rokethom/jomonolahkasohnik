<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\RingPricingRule;
use App\Models\User;
use App\Services\Pricing\DistanceCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RingPricingGeojsonImportService
{
    public function __construct(
        private readonly DistanceCalculator $distanceCalculator,
        private readonly RingPricingService $ringPricing,
    ) {}

    public function import(string $path, User $actor, array $options = []): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'geojson_file' => 'File GeoJSON tidak ditemukan atau tidak bisa dibaca oleh server.',
            ]);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'geojson_file' => 'File GeoJSON tidak valid atau bukan JSON.',
            ]);
        }

        $serviceType = filled($options['service_type'] ?? null)
            ? $this->ringPricing->normalizeServiceType((string) $options['service_type'])
            : null;
        $defaultBranchId = filled($options['branch_id'] ?? null) ? (int) $options['branch_id'] : null;
        $allowedBranchIds = $this->allowedBranchIds($actor, $options['allowed_branch_ids'] ?? null);

        if ($defaultBranchId !== null && $allowedBranchIds !== null && ! in_array($defaultBranchId, $allowedBranchIds, true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang import di luar scope akun ini.',
            ]);
        }

        $polygonMatchPoint = (string) ($options['polygon_match_point'] ?? 'destination_then_pickup');
        $isActive = array_key_exists('is_active', $options) ? (bool) $options['is_active'] : true;
        $features = $this->features($decoded);
        if ($features === []) {
            throw ValidationException::withMessages([
                'geojson_file' => 'GeoJSON harus berisi Feature polygon atau multipolygon.',
            ]);
        }

        $branches = Branch::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->when($allowedBranchIds !== null, fn (Builder $query) => $query->whereIn('id', $allowedBranchIds))
            ->get(['id', 'branch_code', 'name', 'area', 'latitude', 'longitude']);

        if (($options['replace_existing'] ?? false) && $defaultBranchId !== null) {
            RingPricingRule::query()
                ->where('source', 'geojson')
                ->where('branch_id', $defaultBranchId)
                ->when($serviceType !== null, fn (Builder $query) => $query->where('service_type', $serviceType), fn (Builder $query) => $query->whereNull('service_type'))
                ->delete();
        }

        $created = 0;
        $updated = 0;
        $skipped = [];
        $featureIndex = 0;

        foreach ($features as $feature) {
            $featureIndex++;
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $polygons = $this->featurePolygons($feature);

            if ($polygons === []) {
                $skipped[] = $this->skipLabel($properties, $featureIndex, 'bukan polygon');
                continue;
            }

            $ring = $this->normalizeRing($properties['ring'] ?? $properties['Ring'] ?? $properties['RING'] ?? null);
            if ($ring === null) {
                $skipped[] = $this->skipLabel($properties, $featureIndex, 'ring tidak ditemukan');
                continue;
            }

            foreach ($polygons as $polygonIndex => $points) {
                if (count($points) < 3) {
                    $skipped[] = $this->skipLabel($properties, $featureIndex, 'polygon kurang dari 3 titik');
                    continue;
                }

                $branchId = $defaultBranchId ?? $this->nearestBranchId($branches, $this->centroid($points));
                if ($branchId === null) {
                    $skipped[] = $this->skipLabel($properties, $featureIndex, 'cabang tidak ditemukan');
                    continue;
                }

                if ($allowedBranchIds !== null && ! in_array($branchId, $allowedBranchIds, true)) {
                    $skipped[] = $this->skipLabel($properties, $featureIndex, 'di luar scope cabang user');
                    continue;
                }

                $name = $this->ruleName($properties, $ring, $featureIndex, $polygonIndex, count($polygons));
                $rule = RingPricingRule::query()
                    ->where('source', 'geojson')
                    ->where('branch_id', $branchId)
                    ->where('service_type', $serviceType)
                    ->where('name', $name)
                    ->first();

                $data = [
                    'branch_id' => $branchId,
                    'service_type' => $serviceType,
                    'name' => $name,
                    'area_mode' => 'polygon',
                    'pickup_area' => (string) ($properties['pickup_area'] ?? $properties['name'] ?? $name),
                    'destination_area' => (string) ($properties['destination_area'] ?? $properties['name'] ?? $name),
                    'pickup_aliases' => [],
                    'destination_aliases' => [],
                    'polygon_coordinates' => $points,
                    'polygon_match_point' => $polygonMatchPoint,
                    'match_type' => (string) ($properties['match_type'] ?? 'point'),
                    'pickup_ring' => $this->normalizeRing($properties['pickup_ring'] ?? null),
                    'destination_ring' => $this->normalizeRing($properties['destination_ring'] ?? null),
                    'ring' => $ring,
                    ...$this->pricingData($properties, $ring),
                    'is_bidirectional' => true,
                    'source' => 'geojson',
                    'is_active' => $isActive,
                    'updated_by' => $actor->id,
                ];

                if ($rule) {
                    $rule->update($data);
                    $updated++;
                } else {
                    RingPricingRule::query()->create([...$data, 'created_by' => $actor->id]);
                    $created++;
                }
            }
        }

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => 'imported_ring_pricing_geojson',
            'subject_type' => User::class,
            'subject_id' => $actor->id,
            'subject_label' => $actor->name ?? $actor->email,
            'metadata' => [
                'file' => $options['file_name'] ?? basename($path),
                'created' => $created,
                'updated' => $updated,
                'skipped' => count($skipped),
                'branch_id' => $defaultBranchId,
            ],
        ]);

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => count($skipped),
            'errors' => $skipped,
        ];
    }

    private function allowedBranchIds(User $actor, mixed $explicit): ?array
    {
        if (is_array($explicit)) {
            return array_values(array_map('intval', $explicit));
        }

        if (app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($actor->role)) {
            return null;
        }

        return $actor->branch_id ? [(int) $actor->branch_id] : [];
    }

    private function features(array $geojson): array
    {
        if (($geojson['type'] ?? null) === 'FeatureCollection') {
            return array_values(array_filter($geojson['features'] ?? [], 'is_array'));
        }

        if (($geojson['type'] ?? null) === 'Feature') {
            return [$geojson];
        }

        return in_array($geojson['type'] ?? null, ['Polygon', 'MultiPolygon'], true)
            ? [['type' => 'Feature', 'properties' => [], 'geometry' => $geojson]]
            : [];
    }

    private function featurePolygons(array $feature): array
    {
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : $feature;
        $coordinates = $geometry['coordinates'] ?? null;

        if (($geometry['type'] ?? null) === 'Polygon' && is_array($coordinates)) {
            return $this->polygonRings($coordinates);
        }

        if (($geometry['type'] ?? null) === 'MultiPolygon' && is_array($coordinates)) {
            return collect($coordinates)
                ->filter(fn (mixed $polygon): bool => is_array($polygon))
                ->flatMap(fn (array $polygon): array => $this->polygonRings($polygon))
                ->values()
                ->all();
        }

        return [];
    }

    private function polygonRings(array $coordinates): array
    {
        $outerRing = $coordinates[0] ?? [];
        if (! is_array($outerRing)) {
            return [];
        }

        $points = collect($outerRing)
            ->map(fn (mixed $point): ?array => is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])
                ? ['lat' => round((float) $point[1], 8), 'lng' => round((float) $point[0], 8)]
                : null)
            ->filter()
            ->values()
            ->all();

        if (count($points) > 1 && $points[0] === $points[count($points) - 1]) {
            array_pop($points);
        }

        return $points !== [] ? [$points] : [];
    }

    private function normalizeRing(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(?:ring[_\s-]?)?([123])$/', $normalized, $matches) === 1) {
            return 'ring_'.$matches[1];
        }

        return str_starts_with($normalized, 'ring_') ? $normalized : null;
    }

    private function pricingData(array $properties, string $ring): array
    {
        $mode = strtolower((string) ($properties['pricing_mode'] ?? ''));
        $pricingMode = in_array($mode, ['flat', 'formula'], true) ? $mode : ($ring === 'ring_3' ? 'formula' : 'flat');

        $price = $this->int($properties['price'] ?? $properties['base_price'] ?? null);
        $perKmRate = $this->int($properties['per_km_rate'] ?? null);
        $subtractValue = $this->int($properties['subtract_value'] ?? null);

        if ($pricingMode === 'formula') {
            $price = $price ?? 0;
            $perKmRate = $perKmRate ?? 0;
            $subtractValue = $subtractValue ?? 0;
        } else {
            $price = $price ?? 0;
            $perKmRate = null;
            $subtractValue = 0;
        }

        return [
            'min_km' => $this->float($properties['min_km'] ?? null) ?? $this->defaultMinKm($ring),
            'max_km' => $this->float($properties['max_km'] ?? null) ?? $this->defaultMaxKm($ring),
            'pricing_mode' => $pricingMode,
            'price' => $price,
            'per_km_rate' => $perKmRate,
            'subtract_value' => $subtractValue,
            'service_fee' => $this->int($properties['service_fee'] ?? null) ?? $this->defaultServiceFee($ring),
            'priority' => $this->int($properties['priority'] ?? null) ?? $this->defaultPriority($ring),
        ];
    }

    private function ruleName(array $properties, string $ring, int $featureIndex, int $polygonIndex, int $polygonCount): string
    {
        $base = trim((string) ($properties['name'] ?? $properties['Name'] ?? $properties['NAME'] ?? 'GeoJSON'));
        $suffix = $polygonCount > 1 ? sprintf(' #%03d-%02d', $featureIndex, $polygonIndex + 1) : sprintf(' #%03d', $featureIndex);

        return Str::limit(sprintf('%s %s%s', $base, str_replace('_', ' ', $ring), $suffix), 255, '');
    }

    private function centroid(array $points): array
    {
        $total = max(1, count($points));

        return [
            'lat' => array_sum(array_map(fn (array $point): float => (float) $point['lat'], $points)) / $total,
            'lng' => array_sum(array_map(fn (array $point): float => (float) $point['lng'], $points)) / $total,
        ];
    }

    private function nearestBranchId(Collection $branches, array $point): ?int
    {
        $nearest = null;
        $nearestDistance = null;

        foreach ($branches as $branch) {
            $distance = $this->distanceCalculator->haversine((float) $branch->latitude, (float) $branch->longitude, (float) $point['lat'], (float) $point['lng']);
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = (int) $branch->id;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    private function skipLabel(array $properties, int $featureIndex, string $reason): string
    {
        $name = trim((string) ($properties['name'] ?? $properties['Name'] ?? $properties['NAME'] ?? 'Feature'));

        return sprintf('%s #%03d: %s', $name, $featureIndex, $reason);
    }

    private function defaultMinKm(string $ring): float
    {
        return match ($ring) {
            'ring_2' => 4.1,
            'ring_3' => 9.1,
            default => 0.0,
        };
    }

    private function defaultMaxKm(string $ring): ?float
    {
        return match ($ring) {
            'ring_1' => 4.0,
            'ring_2' => 9.0,
            default => null,
        };
    }

    private function defaultServiceFee(string $ring): int
    {
        return in_array($ring, ['ring_1', 'ring_2'], true) ? 1000 : 0;
    }

    private function defaultPriority(string $ring): int
    {
        return match ($ring) {
            'ring_1' => 300,
            'ring_2' => 200,
            'ring_3' => 100,
            default => 0,
        };
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
