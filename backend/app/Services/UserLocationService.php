<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GeofenceArea;
use App\Models\User;
use App\Models\UserLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class UserLocationService
{
    public function __construct(
        private readonly GeofenceService $geofenceService,
        private readonly LocationValidationService $locationValidationService,
    ) {
    }

    public function updateLocation(User $user, float $lat, float $lng, ?float $accuracy = null, mixed $gpsTimestamp = null): array
    {
        $nearest = $this->nearestGeofence($lat, $lng);

        $validation = $this->locationValidationService->validateLocation(
            $user,
            $lat,
            $lng,
            null,
            $accuracy,
            $gpsTimestamp ?? now(),
            [
                'provider' => 'browser_realtime',
                'skip_previous_movement_check' => true,
            ],
        );

        $inside = $validation['is_valid'] && $validation['branch'] !== null;
        $branch = $inside ? $validation['branch'] : null;
        $branchId = Branch::resolveOperationalAreaId($branch?->id, $lat, $lng)
            ?? Branch::resolveOperationalAreaId($user->branch_id, $lat, $lng)
            ?? Branch::resolveOperationalAreaId($nearest['branch']?->id ?? null, $lat, $lng);
        $lockedBranch = $branchId ? Branch::query()->find($branchId) : null;
        $geofence = $inside ? $validation['geofence_area'] : null;
        $distance = $geofence
            ? $this->distanceToGeofence($lat, $lng, $geofence)
            : ($nearest['distance_meters'] ?? null);

        $location = UserLocation::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
                'branch_id' => $lockedBranch?->id,
                'geofence_area_id' => $geofence?->id,
                'distance_meters' => $distance,
                'status' => $inside ? 'inside_branch' : 'outside_branch',
                'gps_timestamp' => $gpsTimestamp ? Carbon::parse($gpsTimestamp) : now(),
            ],
        );

        $user->forceFill([
            'lat' => $lat,
            'lng' => $lng,
            'branch_id' => $lockedBranch?->id,
        ])->save();

        Log::info('user_location.updated', [
            'user_id' => $user->id,
            'user_lat' => $lat,
            'user_lng' => $lng,
            'branch_id' => $lockedBranch?->id,
            'branch_name' => $lockedBranch?->display_name,
            'branch_lat' => $lockedBranch?->latitude,
            'branch_lng' => $lockedBranch?->longitude,
            'nearest_branch_id' => $nearest['branch']?->id,
            'nearest_branch_name' => $nearest['branch']?->display_name,
            'nearest_branch_lat' => $nearest['branch']?->latitude,
            'nearest_branch_lng' => $nearest['branch']?->longitude,
            'distance_meters' => $distance,
            'status' => $location->status,
            'reason' => $validation['reason'],
        ]);

        return [
            'status' => $location->status,
            'inside_branch' => $inside,
            'branch' => $lockedBranch,
            'detected_branch' => $branch,
            'geofence_area' => $geofence,
            'distance_meters' => $distance,
            'nearest_branch' => $nearest['branch'],
            'nearest_distance_meters' => $nearest['distance_meters'],
            'location' => $location->fresh(['branch', 'geofenceArea']),
            'reason' => $validation['reason'],
            'is_suspicious' => $validation['is_suspicious'],
        ];
    }

    public function isInsideBranch(User $user, Branch $branch): bool
    {
        $location = $user->currentLocation;

        if (! $location) {
            return false;
        }

        return $branch->geofenceAreas()
            ->where('is_active', true)
            ->get()
            ->contains(fn (GeofenceArea $area): bool => $this->geofenceService->containsPoint((float) $location->lat, (float) $location->lng, $area));
    }

    private function nearestGeofence(float $lat, float $lng): array
    {
        return GeofenceArea::query()
            ->with('branch')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->operationalAreas())
            ->get()
            ->map(function (GeofenceArea $area) use ($lat, $lng): array {
                return [
                    'area' => $area,
                    'branch' => $area->branch,
                    'distance_meters' => $this->distanceToGeofence($lat, $lng, $area),
                ];
            })
            ->sortBy('distance_meters')
            ->first() ?? ['area' => null, 'branch' => null, 'distance_meters' => null];
    }

    private function distanceToGeofence(float $lat, float $lng, GeofenceArea $area): float
    {
        return $this->geofenceService->distanceInMeters(
            $lat,
            $lng,
            (float) $area->center_latitude,
            (float) $area->center_longitude,
        );
    }
}
