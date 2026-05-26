<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyServiceAnalytic extends Model
{
    protected $fillable = [
        'analytics_date',
        'service_code',
        'service_name',
        'is_total',
        'orders_count',
        'completed_orders',
        'cancelled_orders',
        'unique_customers',
        'repeat_customers',
        'gross_revenue',
        'net_revenue',
        'driver_payout',
        'platform_fee',
        'rating_sum',
        'ratings_count',
        'hourly_orders',
    ];

    protected $casts = [
        'analytics_date' => 'date',
        'is_total' => 'boolean',
        'hourly_orders' => 'array',
    ];
}
