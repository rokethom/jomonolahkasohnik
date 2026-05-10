<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCrew extends Model
{
    protected $fillable = [
        'order_id',
        'driver_id',
        'order_crew_rule_id',
        'role',
        'label',
        'status',
        'service_charge',
        'accepted_at',
    ];

    protected $casts = [
        'service_charge' => 'integer',
        'accepted_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(OrderCrewRule::class, 'order_crew_rule_id');
    }
}
