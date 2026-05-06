<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'area',
        'latitude',
        'longitude',
        'radius_km',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'decimal:2',
    ];

    public function geofenceAreas(): HasMany
    {
        return $this->hasMany(GeofenceArea::class);
    }

    public function locationLogs(): HasMany
    {
        return $this->hasMany(LocationLog::class);
    }

    public function userLocations(): HasMany
    {
        return $this->hasMany(UserLocation::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return collect([$this->name, $this->area])
            ->filter()
            ->implode(' - ');
    }
}
