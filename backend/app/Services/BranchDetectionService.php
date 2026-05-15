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
        return $this->detectByGeofence($lat, $lng);
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
            ->whereHas('branch', fn ($query) => $query->operationalAreas())
            ->orderByDesc('priority')
            ->orderBy('radius_meters')
            ->get()
            ->map(fn (GeofenceArea $area): array => [
                'area' => $area,
                'branch' => $area->branch,
                'distance_meters' => $this->geofenceService->distanceToAreaCenter($lat, $lng, $area),
                'source' => 'geofence',
            ])
            ->first(fn (array $row): bool => $this->geofenceService->containsPoint($lat, $lng, $row['area']));

        if ($match) {
            return $match;
        }

        $nearest = Branch::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->operationalAreas()
            ->get()
            ->map(fn (Branch $branch): array => [
                'area' => null,
                'branch' => $branch,
                'distance_meters' => $this->distanceToBranch($lat, $lng, $branch),
                'source' => 'nearest_branch',
            ])
            ->sortBy('distance_meters')
            ->first();

        return $nearest ?? ['branch' => null, 'area' => null, 'distance_meters' => null, 'source' => 'geofence'];
    }

}
