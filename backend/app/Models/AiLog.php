<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiLog extends Model
{
    use HasFactory;

    protected $fillable = ['workflow', 'agent', 'status', 'request_payload', 'response_payload', 'duration_ms'];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'duration_ms' => 'integer',
    ];
}
