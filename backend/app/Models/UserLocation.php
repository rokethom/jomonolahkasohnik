<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lat',
        'lng',
        'accuracy',
        'branch_id',
        'geofence_area_id',
        'distance_meters',
        'status',
        'gps_timestamp',
    ];

    protected $casts = [
        'lat' => 'decimal:8',
        'lng' => 'decimal:8',
        'accuracy' => 'decimal:2',
        'distance_meters' => 'decimal:2',
        'gps_timestamp' => 'datetime',
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
