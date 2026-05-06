<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverSuspension extends Model
{
    use HasFactory;

    protected $fillable = [
            'driver_id',
            'created_by',
            'type',
            'reason',
            'duration',
            'start_at',
            'end_at',
            'status',
            'metadata',
    ];

    protected $casts = [
        'duration' => 'integer',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
