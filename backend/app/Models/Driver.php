<?php

namespace App\Models;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Driver extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'google_id',
        'vehicle_type',
        'vehicle_types',
        'vehicle_seat_rows',
        'is_ladies_driver',
        'allowed_service_types',
        'vehicle_number',
        'current_lat',
        'current_lng',
        'is_available',
        'status',
        'is_suspend',
        'oper_handle_count',
        'suspended_until',
        'last_login_at',
        'last_login_ip',
        'last_login_device',
        'auth_failed_attempts',
        'auth_locked_until',
        'auth_suspended_at',
        'bpjs_jht_enabled',
        'bansos_amount',
        'permanent_delete_eligible_at',
    ];

    protected $casts = [
        'current_lat' => 'decimal:8',
        'current_lng' => 'decimal:8',
        'allowed_service_types' => 'array',
        'vehicle_types' => 'array',
        'vehicle_seat_rows' => 'integer',
        'is_ladies_driver' => 'boolean',
        'is_available' => 'boolean',
        'is_suspend' => 'boolean',
        'oper_handle_count' => 'integer',
        'suspended_until' => 'datetime',
        'last_login_at' => 'datetime',
        'auth_failed_attempts' => 'integer',
        'auth_locked_until' => 'datetime',
        'auth_suspended_at' => 'datetime',
        'bpjs_jht_enabled' => 'boolean',
        'bansos_amount' => 'integer',
        'permanent_delete_eligible_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(DriverSuspension::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(DriverDeposit::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function operHandleRequests(): HasMany
    {
        return $this->hasMany(OperHandleRequest::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(DriverSetting::class);
    }

    public function vehicleTypes(): array
    {
        $types = $this->vehicle_types;

        if (! is_array($types) || $types === []) {
            $types = [$this->vehicle_type ?: 'motor'];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($type): string => strtolower((string) $type),
            $types,
        ), fn (string $type): bool => in_array($type, ['motor', 'mobil'], true))));
    }

    public function canServeVehicle(?string $preferredVehicle, ?int $requiredSeatRows = null): bool
    {
        if (! in_array($preferredVehicle, ['motor', 'mobil'], true)) {
            return true;
        }

        if (! in_array($preferredVehicle, $this->vehicleTypes(), true)) {
            return false;
        }

        if ($preferredVehicle !== 'mobil') {
            return true;
        }

        return (int) ($this->vehicle_seat_rows ?: 2) >= max(2, min(3, (int) ($requiredSeatRows ?: 2)));
    }

    public function isSuspended(): bool
    {
        return $this->status !== 'active'
            && ($this->suspended_until === null || $this->suspended_until->isFuture());
    }

    public function isLoginSuspended(): bool
    {
        return (bool) ($this->is_suspend ?? false)
            || $this->auth_suspended_at !== null
            || $this->status === DriverStatus::Inactive->value
            || $this->status === DriverStatus::Suspended->value
            || ($this->suspended_until !== null && $this->suspended_until->isFuture());
    }

    public function isAuthLocked(): bool
    {
        return $this->auth_locked_until !== null && $this->auth_locked_until->isFuture();
    }
}
