<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
        ]);

        $request->user()->update($payload);

        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $user = $request->user()->loadMissing(['branch', 'currentLocation.branch']);

        $role = $user->role instanceof UserRole ? $user->role->value : $user->role;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id,
            'branch' => $user->branch?->name,
            'branch_name' => $user->branch?->name,
            'branch_area' => $user->branch?->area,
            'branch_display_name' => $user->branch?->display_name,
            'lat' => $user->lat,
            'lng' => $user->lng,
            'address' => $user->address,
            'area_status' => $user->currentLocation?->status ?? ($user->branch_id ? 'inside_branch' : 'outside_branch'),
            'location_updated_at' => $user->currentLocation?->updated_at?->toIso8601String(),
            'role' => $role,
            'permissions' => $user->permissions(),
            'profile_completed' => filled($user->name) && filled($user->phone) && filled($user->address),
        ];
    }
}
