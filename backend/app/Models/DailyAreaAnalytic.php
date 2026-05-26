<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAreaAnalytic extends Model
{
    protected $fillable = [
        'analytics_date',
        'area_key',
        'area_id',
        'branch_id',
        'area_name',
        'orders_count',
        'completed_orders',
        'cancelled_orders',
        'gross_revenue',
        'platform_fee',
    ];

    protected $casts = [
        'analytics_date' => 'date',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
