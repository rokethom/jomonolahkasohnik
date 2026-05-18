<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivePriceReview extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'token',
        'user_id',
        'branch_id',
        'reviewed_by',
        'order_id',
        'service_type',
        'status',
        'raw_text',
        'parsed',
        'order_payload',
        'quote',
        'system_price',
        'system_service_fee',
        'system_total_price',
        'corrected_price',
        'corrected_service_fee',
        'corrected_extra_charge',
        'corrected_total_price',
        'correction_reason',
        'confirmation_available_at',
        'reviewed_at',
        'consumed_at',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'reviewed_by' => 'integer',
        'order_id' => 'integer',
        'parsed' => 'array',
        'order_payload' => 'array',
        'quote' => 'array',
        'system_price' => 'integer',
        'system_service_fee' => 'integer',
        'system_total_price' => 'integer',
        'corrected_price' => 'integer',
        'corrected_service_fee' => 'integer',
        'corrected_extra_charge' => 'integer',
        'corrected_total_price' => 'integer',
        'confirmation_available_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
