<?php

namespace App\Services;

use App\Models\LocationLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LocationValidationService
{
    public function __construct(
        private readonly GeofenceService $geofenceService,
        private readonly OperationalAreaService $operationalAreas,
    ) {
    }

    public function validateLocation(
        User $user,
        float $lat,
        float $lng,
        ?float $speed,
        ?float $accuracy,
        CarbonInterface|string|null $timestamp,
        array $metadata = [],
    ): array {
        $gpsTimestamp = $timestamp ? Carbon::parse($timestamp) : null;
        $branchId = isset($metadata['branch_id']) ? (int) $metadata['branch_id'] : null;
        $geofence = $this->geofenceService->findValidGeofence($branchId, $lat, $lng);
        $branch = $geofence?->branch;
        $areaId = $geofence?->area_id
            ?? $this->operationalAreas->resolveAreaId(null, $branch?->id, $lat, $lng);
        $fakeGps = $this->detectFakeGps($user, $lat, $lng, $speed, $accuracy, $gpsTimestamp, $metadata);
        $isValid = $geofence !== null && ! $fakeGps['is_suspicious'];

        $log = LocationLog::create([
            'user_id' => $user->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy' => $accuracy,
            'altitude' => $metadata['altitude'] ?? null,
            'speed' => $speed,
            'heading' => $metadata['heading'] ?? null,
            'gps_timestamp' => $gpsTimestamp,
            'provider' => $metadata['provider'] ?? null,
            'is_mock_location' => (bool) ($metadata['is_mock_location'] ?? false),
            'is_valid' => $isValid,
            'geofence_area_id' => $geofence?->id,
            'branch_id' => $branch?->id,
            'area_id' => $areaId,
            'is_suspicious' => $fakeGps['is_suspicious'],
            'suspicion_reason' => $fakeGps['reason'],
        ]);

        return [
            'is_valid' => $isValid,
            'geofence_area' => $geofence,
            'branch' => $branch,
            'is_suspicious' => $fakeGps['is_suspicious'],
            'reason' => $fakeGps['reason'],
            'location_log' => $log->fresh(['user', 'branch', 'area', 'geofenceArea']),
        ];
    }

    private function detectFakeGps(
        User $user,
        float $lat,
        float $lng,
        ?float $speed,
        ?float $accuracy,
        ?CarbonInterface $gpsTimestamp,
        array $metadata,
    ): array {
        $reasons = [];

        if (($metadata['is_mock_location'] ?? false) === true) {
            $reasons[] = 'mock_location_detected';
        }

        if ($gpsTimestamp && abs($gpsTimestamp->diffInSeconds(now(), false)) > 300) {
            $reasons[] = 'gps_timestamp_diff_more_than_5_minutes';
        }

        if ($accuracy !== null && $accuracy < 1) {
            $reasons[] = 'accuracy_less_than_1_meter';
        }

        if ($speed !== null && $speed > 50) {
            $reasons[] = 'speed_more_than_50_mps';
        }

        $previousLog = LocationLog::query()
            ->where('user_id', $user->id)
            ->latest('gps_timestamp')
            ->latest()
            ->first();

        if (($metadata['skip_previous_movement_check'] ?? false) !== true && $previousLog && $gpsTimestamp && $previousLog->gps_timestamp) {
            $seconds = max(1, abs($gpsTimestamp->diffInSeconds($previousLog->gps_timestamp)));
            $distance = $this->geofenceService->distanceInMeters(
                (float) $previousLog->latitude,
                (float) $previousLog->longitude,
                $lat,
                $lng,
            );

            if (($distance / $seconds) > 50) {
                $reasons[] = 'impossible_movement_from_previous_location';
            }
        }

        return [
            'is_suspicious' => $reasons !== [],
            'reason' => $reasons === [] ? null : implode(', ', array_values(array_unique($reasons))),
        ];
    }
}
