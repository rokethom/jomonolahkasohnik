<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'latitude',
        'longitude',
        'accuracy',
        'altitude',
        'speed',
        'heading',
        'gps_timestamp',
        'provider',
        'is_mock_location',
        'is_valid',
        'geofence_area_id',
        'branch_id',
        'is_suspicious',
        'suspicion_reason',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'accuracy' => 'decimal:2',
        'altitude' => 'decimal:2',
        'speed' => 'decimal:2',
        'heading' => 'decimal:2',
        'gps_timestamp' => 'datetime',
        'is_mock_location' => 'boolean',
        'is_valid' => 'boolean',
        'is_suspicious' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function geofenceArea(): BelongsTo
    {
        return $this->belongsTo(GeofenceArea::class);
    }
}
