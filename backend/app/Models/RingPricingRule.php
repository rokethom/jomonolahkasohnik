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
        'area_mode',
        'pickup_area',
        'destination_area',
        'pickup_aliases',
        'destination_aliases',
        'polygon_coordinates',
        'polygon_match_point',
        'match_type',
        'pickup_ring',
        'destination_ring',
        'ring',
        'min_km',
        'max_km',
        'pricing_mode',
        'price',
        'per_km_rate',
        'subtract_value',
        'service_fee',
        'priority',
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
        'polygon_coordinates' => 'array',
        'min_km' => 'decimal:2',
        'max_km' => 'decimal:2',
        'price' => 'integer',
        'per_km_rate' => 'integer',
        'subtract_value' => 'integer',
        'service_fee' => 'integer',
        'priority' => 'integer',
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
        $serviceTypes = self::serviceAliases($serviceType);

        return $query->where(function (Builder $query) use ($serviceTypes): void {
            $query->whereNull('service_type');

            if ($serviceTypes !== []) {
                $query->orWhereIn('service_type', $serviceTypes);
            }
        });
    }

    private static function serviceAliases(?string $serviceType): array
    {
        $value = strtolower(trim((string) $serviceType));
        if ($value === '') {
            return [];
        }

        $aliases = match ($value) {
            'do', 'delivery', 'delivery_order' => ['DO', 'do', 'delivery', 'delivery_order'],
            'kr', 'kurir' => ['KR', 'kr', 'kurir'],
            'oj', 'ojek' => ['OJ', 'oj', 'ojek'],
            'bl', 'belanja' => ['BL', 'bl', 'belanja'],
            'go', 'gift', 'gift_order', 'gift order' => ['GO', 'go', 'gift', 'gift_order', 'gift order'],
            'tv', 'travel' => ['TV', 'tv', 'travel'],
            'jm', 'joker', 'joker_mobil', 'joker mobil', 'mobil' => ['JM', 'jm', 'joker', 'joker_mobil', 'joker mobil', 'mobil'],
            default => [$serviceType, strtoupper((string) $serviceType), $value],
        };

        return array_values(array_unique(array_filter($aliases)));
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
