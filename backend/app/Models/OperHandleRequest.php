<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperHandleRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'driver_id',
        'requested_by',
        'operator_approved_by',
        'spv_approved_by',
        'decided_by',
        'proof_path',
        'reason',
        'status',
        'operator_approved_at',
        'spv_approved_at',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'operator_approved_at' => 'datetime',
        'spv_approved_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
