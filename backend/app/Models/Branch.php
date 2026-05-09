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

    protected static function booted(): void
    {
        static::saved(function (Branch $branch): void {
            $branch->createPrimaryGeofenceAreaIfMissing();
        });
    }

    public function createPrimaryGeofenceAreaIfMissing(): ?GeofenceArea
    {
        if (! is_numeric($this->latitude) || ! is_numeric($this->longitude)) {
            return null;
        }

        $area = $this->geofenceAreas()->oldest('id')->first();
        if ($area) {
            return $area;
        }

        return $this->geofenceAreas()->create([
            'name' => $this->default_geofence_name,
            'description' => 'Default geofence dari titik cabang. Radius utama tetap diatur dari menu Geofence Area.',
            'center_latitude' => $this->latitude,
            'center_longitude' => $this->longitude,
            'radius_meters' => 5000,
            'is_active' => true,
            'priority' => 10,
        ]);
    }

    public function getDefaultGeofenceNameAttribute(): string
    {
        return $this->display_name ?: 'Area Cabang';
    }
}
