<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLog extends Model
{
    use HasFactory;

    public const STATUS_STARTED = 'started';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'source',
        'event',
        'status',
        'queue',
        'user_id',
        'actor_id',
        'branch_id',
        'order_id',
        'live_price_review_id',
        'provider',
        'model',
        'duration_ms',
        'input_payload',
        'output_payload',
        'message',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'actor_id' => 'integer',
        'branch_id' => 'integer',
        'order_id' => 'integer',
        'live_price_review_id' => 'integer',
        'duration_ms' => 'integer',
        'input_payload' => 'array',
        'output_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function livePriceReview(): BelongsTo
    {
        return $this->belongsTo(LivePriceReview::class);
    }
}
