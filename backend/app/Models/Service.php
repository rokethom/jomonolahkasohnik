<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'is_active',
        'sort_order',
        'form_schema',
        'whatsapp_redirect_enabled',
        'outside_area_only',
        'whatsapp_number',
        'whatsapp_message_template',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'form_schema' => 'array',
        'whatsapp_redirect_enabled' => 'boolean',
        'outside_area_only' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
