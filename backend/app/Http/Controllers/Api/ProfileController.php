<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'profile_photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('profile_photo')) {
            if ($request->user()->profile_photo_path) {
                Storage::disk('public')->delete($request->user()->profile_photo_path);
            }

            $payload['profile_photo_path'] = $request->file('profile_photo')?->store('profiles/customers', 'public');
        }

        unset($payload['profile_photo']);
        $request->user()->update($payload);
        $request->user()->refresh();

        return response()->json($this->payload($request));
    }

    private function payload(Request $request): array
    {
        $user = $request->user()->loadMissing(['branch', 'currentLocation.branch']);
        $branch = $user->branch ?: $user->currentLocation?->branch;

        $role = $user->role instanceof UserRole ? $user->role->value : $user->role;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id ?? $branch?->id,
            'branch' => $branch?->name,
            'branch_name' => $branch?->name,
            'branch_area' => $branch?->area,
            'branch_display_name' => $branch?->display_name,
            'lat' => $user->lat,
            'lng' => $user->lng,
            'address' => $user->address,
            'profile_photo_url' => $user->profile_photo_path ? $this->publicMediaUrl($request, $user->profile_photo_path) : null,
            'area_status' => $user->currentLocation?->status ?? ($user->branch_id ? 'inside_branch' : 'outside_branch'),
            'location_updated_at' => $user->currentLocation?->updated_at?->toIso8601String(),
            'role' => $role,
            'permissions' => $user->permissions(),
            'profile_completed' => filled($user->name) && filled($user->phone) && filled($user->address),
        ];
    }

    private function publicMediaUrl(Request $request, string $path): string
    {
        return $request->getSchemeAndHttpHost().'/api/media/'.ltrim($path, '/');
    }
}
