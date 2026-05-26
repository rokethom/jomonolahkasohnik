<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyDriverAnalytic extends Model
{
    protected $fillable = [
        'analytics_date',
        'driver_id',
        'assigned_orders',
        'accepted_orders',
        'completed_orders',
        'cancelled_orders',
        'gross_revenue',
        'driver_payout',
        'platform_fee',
        'rating_sum',
        'ratings_count',
    ];

    protected $casts = [
        'analytics_date' => 'date',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
