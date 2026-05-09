<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingPricingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'service_type',
        'name',
        'pickup_area',
        'destination_area',
        'pickup_aliases',
        'destination_aliases',
        'ring',
        'price',
        'is_bidirectional',
        'source',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'pickup_aliases' => 'array',
        'destination_aliases' => 'array',
        'price' => 'integer',
        'is_bidirectional' => 'boolean',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->where(function (Builder $query) use ($branchId): void {
            $query->where('branch_id', $branchId)
                ->orWhereNull('branch_id');
        });
    }

    public function scopeForService(Builder $query, ?string $serviceType): Builder
    {
        return $query->where(function (Builder $query) use ($serviceType): void {
            $query->whereNull('service_type');

            if ($serviceType) {
                $query->orWhere('service_type', $serviceType);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
