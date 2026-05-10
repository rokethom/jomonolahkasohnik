<?php

namespace App\Services;

use App\Models\GeofenceArea;

class GeofenceService
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public function containsPoint(float $lat, float $lng, ?GeofenceArea $geofenceArea = null): bool
    {
        if (! $geofenceArea) {
            return GeofenceArea::query()
                ->where('is_active', true)
                ->get()
                ->contains(fn (GeofenceArea $area): bool => $this->containsPoint($lat, $lng, $area));
        }

        if (($geofenceArea->shape_type ?? 'circle') === 'polygon' && ! empty($geofenceArea->polygon_coordinates)) {
            return $this->pointInPolygon($lat, $lng, $geofenceArea->polygon_coordinates);
        }

        return $this->distanceInMeters(
            $lat,
            $lng,
            (float) $geofenceArea->center_latitude,
            (float) $geofenceArea->center_longitude,
        ) <= (int) $geofenceArea->radius_meters;
    }

    public function findValidGeofence(?int $branchId, float $lat, float $lng): ?GeofenceArea
    {
        return GeofenceArea::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderByDesc('priority')
            ->orderBy('radius_meters')
            ->get()
            ->first(fn (GeofenceArea $area): bool => $this->containsPoint($lat, $lng, $area));
    }

    public function distanceToAreaCenter(float $lat, float $lng, GeofenceArea $area): float
    {
        return $this->distanceInMeters(
            $lat,
            $lng,
            (float) $area->center_latitude,
            (float) $area->center_longitude,
        );
    }

    public function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDistance = deg2rad($lat2 - $lat1);
        $lngDistance = deg2rad($lng2 - $lng1);

        $a = sin($latDistance / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDistance / 2) ** 2;

        return self::EARTH_RADIUS_METERS * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * @param  array<int, array{lat?: mixed, lng?: mixed, latitude?: mixed, longitude?: mixed}>  $polygon
     */
    public function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $points = collect($polygon)
            ->map(fn (array $point): array => [
                'lat' => (float) ($point['lat'] ?? $point['latitude'] ?? 0),
                'lng' => (float) ($point['lng'] ?? $point['longitude'] ?? 0),
            ])
            ->filter(fn (array $point): bool => $point['lat'] !== 0.0 || $point['lng'] !== 0.0)
            ->values()
            ->all();

        $count = count($points);
        if ($count < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $pointI = $points[$i];
            $pointJ = $points[$j];

            $intersects = (($pointI['lat'] > $lat) !== ($pointJ['lat'] > $lat))
                && ($lng < ($pointJ['lng'] - $pointI['lng']) * ($lat - $pointI['lat']) / (($pointJ['lat'] - $pointI['lat']) ?: 0.0000001) + $pointI['lng']);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
