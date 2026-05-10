<?php

namespace App\Http\Resources;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Driver */
class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->loadMissing('user.branch');

        return [
            'id' => $this->id,
            'name' => $this->name ?: $this->user?->name,
            'username' => $this->user?->username,
            'email' => $this->email ?: $this->user?->email,
            'phone' => $this->user?->phone,
            'branch_id' => $this->user?->branch_id,
            'branch' => $this->user?->branch?->name,
            'vehicle_type' => $this->vehicle_type,
            'vehicle_types' => $this->vehicleTypes(),
            'vehicle_seat_rows' => $this->vehicle_seat_rows,
            'vehicle_number' => $this->vehicle_number,
            'is_ladies_driver' => (bool) $this->is_ladies_driver,
            'status' => $this->status,
            'is_suspend' => (bool) ($this->is_suspend ?? false),
            'auth_suspended_at' => $this->auth_suspended_at?->toIso8601String(),
            'auth_locked_until' => $this->auth_locked_until?->toIso8601String(),
            'auth_failed_attempts' => (int) ($this->auth_failed_attempts ?? 0),
            'is_available' => (bool) $this->is_available,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'last_login_device' => $this->last_login_device,
        ];
    }
}
