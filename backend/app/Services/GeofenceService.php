<?php

namespace App\Services;

use App\Models\Branch;
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

        return $this->distanceInMeters(
            $lat,
            $lng,
            (float) $geofenceArea->center_latitude,
            (float) $geofenceArea->center_longitude,
        ) <= (int) $geofenceArea->radius_meters;
    }

    public function findValidGeofence(?int $branchId, float $lat, float $lng): ?GeofenceArea
    {
        if ($branchId) {
            Branch::query()->find($branchId)?->syncPrimaryGeofenceArea();
        }

        return GeofenceArea::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderByDesc('priority')
            ->orderBy('radius_meters')
            ->get()
            ->first(fn (GeofenceArea $area): bool => $this->containsPoint($lat, $lng, $area));
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
}
