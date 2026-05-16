<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_code',
        'parent_branch_id',
        'name',
        'area',
        'latitude',
        'longitude',
        'radius_km',
        'pricing_origin_name',
        'pricing_origin_latitude',
        'pricing_origin_longitude',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'decimal:2',
        'pricing_origin_latitude' => 'decimal:8',
        'pricing_origin_longitude' => 'decimal:8',
        'is_active' => 'boolean',
        'parent_branch_id' => 'integer',
    ];

    protected $appends = [
        'display_name',
        'is_regency',
        'is_operational_area',
    ];

    public function geofenceAreas(): HasMany
    {
        return $this->hasMany(GeofenceArea::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'parent_branch_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Branch::class, 'parent_branch_id');
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

    public function getIsRegencyAttribute(): bool
    {
        return $this->parent_branch_id === null && blank($this->area);
    }

    public function getIsOperationalAreaAttribute(): bool
    {
        return ! $this->is_regency;
    }

    public function hasPricingOrigin(): bool
    {
        return is_numeric($this->pricing_origin_latitude)
            && is_numeric($this->pricing_origin_longitude);
    }

    /**
     * @return array{lat: float, lng: float, name: string, source: string}|null
     */
    public function pricingOriginPoint(): ?array
    {
        $lat = $this->hasPricingOrigin() ? $this->pricing_origin_latitude : $this->latitude;
        $lng = $this->hasPricingOrigin() ? $this->pricing_origin_longitude : $this->longitude;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'name' => filled($this->pricing_origin_name)
                ? (string) $this->pricing_origin_name
                : ($this->display_name ?: 'Titik Nol Pricing'),
            'source' => $this->hasPricingOrigin() ? 'pricing_origin' : 'branch_center',
        ];
    }

    public function scopeRegencies(Builder $query): Builder
    {
        return $query->whereNull('parent_branch_id')
            ->where(fn (Builder $query): Builder => $query->whereNull('area')->orWhere('area', ''));
    }

    public function scopeOperationalAreas(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNotNull('parent_branch_id')
                ->orWhereNotNull('area');
        });
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<int, int>
     */
    public static function expandToOperationalAreaIds(array $branchIds): array
    {
        $branchIds = collect($branchIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($branchIds === []) {
            return [];
        }

        $childIds = static::query()
            ->whereIn('parent_branch_id', $branchIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $selectedOperationalIds = static::query()
            ->whereIn('id', $branchIds)
            ->operationalAreas()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$selectedOperationalIds, ...$childIds]));
    }

    public function isRegency(): bool
    {
        return $this->getIsRegencyAttribute();
    }

    public function isOperationalArea(): bool
    {
        return $this->getIsOperationalAreaAttribute();
    }

    public function nearestOperationalChild(?float $lat = null, ?float $lng = null): ?self
    {
        if ($this->isOperationalArea()) {
            return $this;
        }

        $children = $this->children()
            ->operationalAreas()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        if ($children->isEmpty()) {
            return null;
        }

        if ($lat !== null && $lng !== null) {
            return $children
                ->sortBy(fn (Branch $branch): float => self::distanceInMeters($lat, $lng, (float) $branch->latitude, (float) $branch->longitude))
                ->first();
        }

        return $children->sortBy('area')->first();
    }

    public static function resolveOperationalAreaId(?int $branchId, ?float $lat = null, ?float $lng = null): ?int
    {
        if ($branchId === null) {
            return null;
        }

        $branch = static::query()
            ->with('children:id,parent_branch_id,branch_code,name,area,latitude,longitude,is_active')
            ->find($branchId);

        return $branch?->nearestOperationalChild($lat, $lng)?->id;
    }

    private static function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDistance = deg2rad($lat2 - $lat1);
        $lngDistance = deg2rad($lng2 - $lng1);

        $a = sin($latDistance / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDistance / 2) ** 2;

        return 6371000.0 * (2 * atan2(sqrt($a), sqrt(1 - $a)));
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
        if (blank($area)) {
            return $regency;
        }

        $areaCode = static::areaCode((string) $area, $regency);

        return str_starts_with($areaCode, $regency) ? $areaCode : $regency.$areaCode;
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
        $base = $code !== '' ? substr($code, 0, 20) : 'BRNARE';
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
