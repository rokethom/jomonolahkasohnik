<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCrewRule extends Model
{
    protected $fillable = [
        'name',
        'keywords',
        'service_scopes',
        'helper_role',
        'helper_label',
        'helper_service_charge',
        'helper_base_distance_km',
        'helper_base_price',
        'helper_over_distance_percent',
        'requires_helper',
        'is_active',
        'priority',
        'description',
    ];

    protected $casts = [
        'service_scopes' => 'array',
        'helper_service_charge' => 'integer',
        'helper_base_distance_km' => 'float',
        'helper_base_price' => 'integer',
        'helper_over_distance_percent' => 'float',
        'requires_helper' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
