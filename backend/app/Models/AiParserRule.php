<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiParserRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'text_hash',
        'normalized_text',
        'service_type',
        'ai_data',
        'example_text',
        'provider',
        'model',
        'is_active',
        'hit_count',
        'last_used_at',
    ];

    protected $casts = [
        'ai_data' => 'array',
        'is_active' => 'boolean',
        'hit_count' => 'integer',
        'last_used_at' => 'datetime',
    ];
}
