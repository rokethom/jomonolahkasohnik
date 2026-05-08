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
        'form_schema',
        'whatsapp_redirect_enabled',
        'whatsapp_number',
        'whatsapp_message_template',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'form_schema' => 'array',
        'whatsapp_redirect_enabled' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
