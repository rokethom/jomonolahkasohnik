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
        $role = $request->user()->role->value ?? $request->user()->role;
        abort_if(in_array($role, ['admin', 'gm'], true), 403, 'Lokasi admin tidak dicatat.');

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
                    'branch_code' => $result['branch']->branch_code,
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
                    'branch_code' => $result['nearest_branch']->branch_code,
                    'name' => $result['nearest_branch']->name,
                    'area' => $result['nearest_branch']->area,
                    'display_name' => $result['nearest_branch']->display_name,
                ] : null,
                'nearest_distance_meters' => $result['nearest_distance_meters'],
                'is_suspicious' => $result['is_suspicious'],
                'reason' => $result['reason'],
                'user' => $this->userPayload($request),
            ],
        ]);
    }

    private function userPayload(Request $request): array
    {
        $user = $request->user()->fresh(['branch', 'currentLocation.branch']);
        $branch = $user->branch ?: $user->currentLocation?->branch;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id ?? $branch?->id,
            'branch' => $branch?->name,
            'branch_code' => $branch?->branch_code,
            'branch_name' => $branch?->name,
            'branch_area' => $branch?->area,
            'branch_display_name' => $branch?->display_name,
            'lat' => $user->lat,
            'lng' => $user->lng,
            'address' => $user->address,
            'profile_photo_url' => $user->profile_photo_path ? $request->getSchemeAndHttpHost().'/api/media/'.ltrim($user->profile_photo_path, '/') : null,
            'area_status' => $user->currentLocation?->status ?? ($user->branch_id ? 'inside_branch' : 'outside_branch'),
            'location_updated_at' => $user->currentLocation?->updated_at?->toIso8601String(),
            'role' => $user->role->value ?? $user->role,
            'permissions' => $user->permissions(),
            'profile_completed' => filled($user->name) && filled($user->phone) && filled($user->address),
        ];
    }
}
