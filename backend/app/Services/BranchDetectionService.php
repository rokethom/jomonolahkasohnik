<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GeofenceArea;

class BranchDetectionService
{
    public function __construct(private readonly GeofenceService $geofenceService)
    {
    }

    public function detect(float $lat, float $lng): array
    {
        $geofenceMatch = $this->detectByGeofence($lat, $lng);

        if ($geofenceMatch['branch']) {
            return $geofenceMatch;
        }

        return $this->detectByBranchRadius($lat, $lng);
    }

    public function distanceToBranch(float $lat, float $lng, Branch $branch): float
    {
        return $this->geofenceService->distanceInMeters(
            $lat,
            $lng,
            (float) $branch->latitude,
            (float) $branch->longitude,
        );
    }

    private function detectByGeofence(float $lat, float $lng): array
    {
        $match = GeofenceArea::query()
            ->with('branch')
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('radius_meters')
            ->get()
            ->map(fn (GeofenceArea $area): array => [
                'area' => $area,
                'branch' => $area->branch,
                'distance_meters' => $this->geofenceService->distanceInMeters(
                    $lat,
                    $lng,
                    (float) $area->center_latitude,
                    (float) $area->center_longitude,
                ),
                'source' => 'geofence',
            ])
            ->first(fn (array $row): bool => $row['distance_meters'] <= (float) $row['area']->radius_meters);

        return $match ?? ['branch' => null, 'area' => null, 'distance_meters' => null, 'source' => 'geofence'];
    }

    private function detectByBranchRadius(float $lat, float $lng): array
    {
        $nearest = Branch::query()
            ->get()
            ->map(fn (Branch $branch): array => [
                'branch' => $branch,
                'area' => null,
                'distance_meters' => $this->distanceToBranch($lat, $lng, $branch),
                'radius_meters' => ((float) ($branch->radius_km ?? 5)) * 1000,
                'source' => 'branch_radius',
            ])
            ->sortBy('distance_meters')
            ->first();

        if (! $nearest || $nearest['distance_meters'] > $nearest['radius_meters']) {
            return [
                'branch' => null,
                'area' => null,
                'distance_meters' => $nearest['distance_meters'] ?? null,
                'nearest_branch' => $nearest['branch'] ?? null,
                'source' => 'branch_radius',
            ];
        }

        return $nearest;
    }
}
