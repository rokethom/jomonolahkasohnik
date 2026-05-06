<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Location\ValidateLocationRequest;
use App\Services\LocationValidationService;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function validateLocation(
        ValidateLocationRequest $request,
        LocationValidationService $locationValidationService,
    ): JsonResponse {
        $payload = $request->validated();

        $result = $locationValidationService->validateLocation(
            $request->user(),
            (float) $payload['latitude'],
            (float) $payload['longitude'],
            isset($payload['speed']) ? (float) $payload['speed'] : null,
            isset($payload['accuracy']) ? (float) $payload['accuracy'] : null,
            $payload['gps_timestamp'] ?? null,
            $payload,
        );

        return response()->json([
            'is_valid' => $result['is_valid'],
            'geofence_area' => $result['geofence_area'],
            'branch' => $result['branch'],
            'is_suspicious' => $result['is_suspicious'],
            'reason' => $result['reason'],
            'location_log' => $result['location_log'],
        ]);
    }
}
