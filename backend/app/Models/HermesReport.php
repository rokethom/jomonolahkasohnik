<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HermesReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'command',
        'instruction',
        'model',
        'status',
        'duration_ms',
        'context_summary',
        'report',
        'error_message',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'context_summary' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
