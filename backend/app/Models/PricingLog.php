<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'pickup_latitude', 'pickup_longitude', 'destination_latitude', 'destination_longitude',
        'branch_id', 'area_id', 'pricing_ring_id', 'distance_km', 'calculated_price',
        'pricing_formula', 'response_payload',
    ];

    protected $casts = [
        'pickup_latitude' => 'decimal:8',
        'pickup_longitude' => 'decimal:8',
        'destination_latitude' => 'decimal:8',
        'destination_longitude' => 'decimal:8',
        'branch_id' => 'integer',
        'area_id' => 'integer',
        'pricing_ring_id' => 'integer',
        'distance_km' => 'decimal:2',
        'calculated_price' => 'integer',
        'response_payload' => 'array',
    ];

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function area(): BelongsTo { return $this->belongsTo(Area::class); }
    public function pricingRing(): BelongsTo { return $this->belongsTo(PricingRing::class); }
}
