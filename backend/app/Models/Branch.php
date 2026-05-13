<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_code',
        'code',
        'name',
        'area',
        'latitude',
        'longitude',
        'radius_km',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'display_name',
    ];

    public function geofenceAreas(): HasMany
    {
        return $this->hasMany(GeofenceArea::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function geojsonRegions(): HasMany
    {
        return $this->hasMany(GeojsonRegion::class);
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
        return collect([$this->branch_code, $this->name, $this->area])
            ->filter()
            ->implode(' - ');
    }

    protected static function booted(): void
    {
        static::saving(function (Branch $branch): void {
            if (blank($branch->branch_code)) {
                $branch->branch_code = static::makeBranchCode($branch->name, $branch->area);
            }

            $branch->branch_code = static::uniqueBranchCode(
                strtoupper(trim((string) $branch->branch_code)),
                $branch->exists ? (int) $branch->id : null,
            );

            if (blank($branch->code)) {
                $branch->code = $branch->branch_code;
            }
        });

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
            'shape_type' => 'circle',
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

    public static function makeBranchCode(?string $name, ?string $area): string
    {
        $regency = static::regencyCode((string) $name);
        $areaCode = static::areaCode((string) $area, $regency);

        return $regency.'-'.$areaCode;
    }

    private static function regencyCode(string $name): string
    {
        $normalized = str($name)->lower()->squish()->toString();

        return match (true) {
            str_contains($normalized, 'situbondo') => 'STB',
            str_contains($normalized, 'bondowoso') => 'BWS',
            str_contains($normalized, 'probolinggo') => 'PBL',
            str_contains($normalized, 'banyuwangi') => 'BWI',
            str_contains($normalized, 'jember') => 'JBR',
            default => static::lettersCode($name, 'BRN'),
        };
    }

    private static function areaCode(string $area, string $regencyCode): string
    {
        $normalized = str($area)->lower()->replace(['-', '_', '/', ','], ' ')->squish()->toString();

        $map = [
            'asembagus' => 'ASB',
            'kota' => $regencyCode === 'STB' ? 'STBKT' : ($regencyCode === 'BWS' ? 'BWSKT' : 'KTA'),
            'besuki' => 'BSK',
            'paiton' => 'PTN',
            'kraksaan' => 'KRK',
            'bondowoso kota' => 'BWSKT',
            'rogojampi' => 'RGJ',
            'srono' => 'SRN',
            'muncar' => 'MCR',
            'genteng' => 'GTG',
        ];

        foreach ($map as $needle => $code) {
            if (str_contains($normalized, $needle)) {
                return $code;
            }
        }

        $firstToken = strtoupper((string) str($area)->replace(['-', '_', '/', ','], ' ')->squish()->before(' '));
        if (preg_match('/^[A-Z]{2,4}$/', $firstToken) === 1 && $firstToken !== $regencyCode) {
            return $firstToken;
        }

        return static::lettersCode($area, 'ARE');
    }

    private static function lettersCode(string $value, string $fallback): string
    {
        $letters = preg_replace('/[^a-z0-9]/i', '', $value) ?: $fallback;

        return strtoupper(substr($letters, 0, 3));
    }

    private static function uniqueBranchCode(string $code, ?int $branchId = null): string
    {
        $base = $code !== '' ? substr($code, 0, 20) : 'BRN-ARE';
        $candidate = $base;
        $counter = 2;

        while (static::query()
            ->when($branchId, fn ($query) => $query->where('id', '!=', $branchId))
            ->where('branch_code', $candidate)
            ->exists()
        ) {
            $suffix = '-'.$counter;
            $candidate = substr($base, 0, 20 - strlen($suffix)).$suffix;
            $counter++;
        }

        return $candidate;
    }
}
