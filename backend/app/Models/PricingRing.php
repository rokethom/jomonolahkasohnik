<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingRing extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'min_km', 'max_km', 'formula_type', 'base_price', 'service_fee',
        'per_km_price', 'deduction', 'priority', 'effective_date', 'expired_date',
        'version', 'is_active',
    ];

    protected $casts = [
        'min_km' => 'decimal:2',
        'max_km' => 'decimal:2',
        'base_price' => 'integer',
        'service_fee' => 'integer',
        'per_km_price' => 'integer',
        'deduction' => 'integer',
        'priority' => 'integer',
        'effective_date' => 'date',
        'expired_date' => 'date',
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActiveForDate(Builder $query, ?string $date = null): Builder
    {
        $date ??= now()->toDateString();

        return $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('effective_date')->orWhere('effective_date', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('expired_date')->orWhere('expired_date', '>=', $date));
    }
}
