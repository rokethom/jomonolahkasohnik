<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'branch_id',
        'min_km',
        'max_km',
        'price',
        'is_formula',
        'per_km_rate',
        'subtract_value',
        'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'min_km' => 'decimal:2',
        'max_km' => 'decimal:2',
        'price' => 'integer',
        'is_formula' => 'boolean',
        'per_km_rate' => 'integer',
        'subtract_value' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForDistance(Builder $query, float $distanceKm): Builder
    {
        $distanceKm = max(0.0, $distanceKm);

        return $query
            ->where('min_km', '<=', $distanceKm)
            ->where(function (Builder $query) use ($distanceKm): void {
                $query->whereNull('max_km')
                    ->orWhere('max_km', '>=', $distanceKm);
            });
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(function (Builder $query) use ($branchId): void {
            $query->where('branch_id', $branchId)
                ->orWhereNull('branch_id');
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
