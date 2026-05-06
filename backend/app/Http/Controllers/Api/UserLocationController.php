<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserLocationController extends Controller
{
    public function store(Request $request, UserLocationService $locations): JsonResponse
    {
        $payload = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'gps_timestamp' => ['nullable', 'date'],
        ]);

        $result = $locations->updateLocation(
            $request->user(),
            (float) $payload['lat'],
            (float) $payload['lng'],
            isset($payload['accuracy']) ? (float) $payload['accuracy'] : null,
            $payload['gps_timestamp'] ?? null,
        );

        return response()->json([
            'data' => [
                'status' => $result['status'],
                'inside_branch' => $result['inside_branch'],
                'branch' => $result['branch'] ? [
                    'id' => $result['branch']->id,
                    'name' => $result['branch']->name,
                    'area' => $result['branch']->area,
                    'display_name' => $result['branch']->display_name,
                ] : null,
                'geofence_area' => $result['geofence_area'] ? [
                    'id' => $result['geofence_area']->id,
                    'name' => $result['geofence_area']->name,
                    'radius_meters' => $result['geofence_area']->radius_meters,
                ] : null,
                'distance_meters' => $result['distance_meters'],
                'nearest_branch' => $result['nearest_branch'] ? [
                    'id' => $result['nearest_branch']->id,
                    'name' => $result['nearest_branch']->name,
                    'area' => $result['nearest_branch']->area,
                    'display_name' => $result['nearest_branch']->display_name,
                ] : null,
                'nearest_distance_meters' => $result['nearest_distance_meters'],
                'is_suspicious' => $result['is_suspicious'],
                'reason' => $result['reason'],
                'user' => $request->user()->fresh('branch'),
            ],
        ]);
    }
}
