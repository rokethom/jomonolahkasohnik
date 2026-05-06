<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDeposit extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'year',
        'month',
        'handle_day_15',
        'handle_day_30',
        'bansos',
        'bpjs',
        'bpjs_jht',
        'paid_amount',
        'total',
        'due_date',
        'paid_at',
        'status',
        'breakdown',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'handle_day_15' => 'integer',
        'handle_day_30' => 'integer',
        'bansos' => 'integer',
        'bpjs' => 'integer',
        'bpjs_jht' => 'integer',
        'paid_amount' => 'integer',
        'total' => 'integer',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'breakdown' => 'array',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
