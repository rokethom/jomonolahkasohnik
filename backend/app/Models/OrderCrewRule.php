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
        'requires_helper',
        'is_active',
        'priority',
        'description',
    ];

    protected $casts = [
        'service_scopes' => 'array',
        'helper_service_charge' => 'integer',
        'requires_helper' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
