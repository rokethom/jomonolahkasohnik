<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingPricingSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'service_type',
        'pickup_area',
        'destination_area',
        'ring',
        'suggestion_type',
        'learning_source',
        'suggested_price',
        'previous_price',
        'system_price',
        'price_delta',
        'occurrence_count',
        'confidence',
        'sample_order_ids',
        'evidence',
        'last_order_id',
        'last_edited_by',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'suggested_price' => 'integer',
        'previous_price' => 'integer',
        'system_price' => 'integer',
        'price_delta' => 'integer',
        'occurrence_count' => 'integer',
        'confidence' => 'integer',
        'sample_order_ids' => 'array',
        'evidence' => 'array',
        'last_order_id' => 'integer',
        'last_edited_by' => 'integer',
        'approved_at' => 'datetime',
        'approved_by' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lastOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'last_order_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
