<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class LocationService
{
    public function __construct(
        private readonly GeocodingService $geocodingService,
        private readonly BranchDetectionService $branchDetectionService,
        private readonly LocationValidationService $locationValidationService,
    ) {
    }

    public function updateRegistrationLocation(User $user, float $lat, float $lng, ?float $accuracy = null, mixed $gpsTimestamp = null): array
    {
        $address = $this->geocodingService->getAddressFromLatLng($lat, $lng);
        $detected = $this->branchDetectionService->detect($lat, $lng);
        $branch = $detected['branch'] ?? null;
        $geofence = $detected['area'] ?? null;

        $this->locationValidationService->validateLocation(
            $user,
            $lat,
            $lng,
            null,
            $accuracy,
            $gpsTimestamp ?? now(),
            [
                'provider' => 'browser_registration',
                'skip_previous_movement_check' => true,
            ],
        );

        $user->forceFill([
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address['formatted_address'],
            'branch_id' => $branch?->id,
        ])->save();

        $location = UserLocation::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'lat' => $lat,
                'lng' => $lng,
                'accuracy' => $accuracy,
                'branch_id' => $branch?->id,
                'geofence_area_id' => $geofence?->id,
                'distance_meters' => $detected['distance_meters'] ?? null,
                'status' => $branch ? 'inside_branch' : 'outside_branch',
                'gps_timestamp' => $gpsTimestamp ? Carbon::parse($gpsTimestamp) : now(),
            ],
        );

        Log::info('register_location.detected', [
            'user_id' => $user->id,
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'branch_id' => $branch?->id,
            'branch_name' => $branch?->display_name,
            'branch_lat' => $branch?->latitude,
            'branch_lng' => $branch?->longitude,
            'radius_km' => $branch?->radius_km,
            'distance_meters' => $detected['distance_meters'] ?? null,
            'source' => $detected['source'] ?? null,
        ]);

        return [
            'address' => $address,
            'branch' => $branch,
            'geofence_area' => $geofence,
            'distance_meters' => $detected['distance_meters'] ?? null,
            'location' => $location->fresh(['branch', 'geofenceArea']),
        ];
    }
}
