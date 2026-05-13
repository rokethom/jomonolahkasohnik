<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'provider', 'model_key', 'configuration', 'is_active'];

    protected $casts = [
        'configuration' => 'array',
        'is_active' => 'boolean',
    ];
}
