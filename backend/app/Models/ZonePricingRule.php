<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZonePricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'branch_id',
        'geofence_area_id',
        'service_type',
        'match_point',
        'price_mode',
        'amount',
        'percent',
        'min_km',
        'max_km',
        'is_active',
        'priority',
        'notes',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'geofence_area_id' => 'integer',
        'amount' => 'integer',
        'percent' => 'decimal:2',
        'min_km' => 'decimal:2',
        'max_km' => 'decimal:2',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function geofenceArea(): BelongsTo
    {
        return $this->belongsTo(GeofenceArea::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(function (Builder $query) use ($branchId): void {
            $query->whereNull('branch_id');

            if ($branchId !== null) {
                $query->orWhere('branch_id', $branchId);
            }
        });
    }

    public function scopeForDistance(Builder $query, float $distanceKm): Builder
    {
        return $query
            ->where(function (Builder $query) use ($distanceKm): void {
                $query->whereNull('min_km')
                    ->orWhere('min_km', '<=', $distanceKm);
            })
            ->where(function (Builder $query) use ($distanceKm): void {
                $query->whereNull('max_km')
                    ->orWhere('max_km', '>=', $distanceKm);
            });
    }
}
