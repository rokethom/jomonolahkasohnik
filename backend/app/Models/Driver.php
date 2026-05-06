<?php

namespace App\Models;

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
        'vehicle_type',
        'allowed_service_types',
        'vehicle_number',
        'current_lat',
        'current_lng',
        'is_available',
        'status',
        'oper_handle_count',
        'suspended_until',
        'bpjs_jht_enabled',
        'bansos_amount',
        'permanent_delete_eligible_at',
    ];

    protected $casts = [
        'current_lat' => 'decimal:8',
        'current_lng' => 'decimal:8',
        'allowed_service_types' => 'array',
        'is_available' => 'boolean',
        'oper_handle_count' => 'integer',
        'suspended_until' => 'datetime',
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

    public function isSuspended(): bool
    {
        return $this->status !== 'active'
            && ($this->suspended_until === null || $this->suspended_until->isFuture());
    }
}
