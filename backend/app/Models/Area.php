<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'legacy_branch_id',
        'name',
        'code',
        'latitude',
        'longitude',
        'radius_km',
        'pricing_origin_name',
        'pricing_origin_latitude',
        'pricing_origin_longitude',
        'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'legacy_branch_id' => 'integer',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'decimal:2',
        'pricing_origin_latitude' => 'decimal:8',
        'pricing_origin_longitude' => 'decimal:8',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function legacyBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'legacy_branch_id');
    }

    public function geojsonRegions(): HasMany
    {
        return $this->hasMany(GeojsonRegion::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return collect([$this->code, $this->branch?->name, $this->name])
            ->filter()
            ->implode(' - ');
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
                : ($this->display_name ?: 'Titik Nol Area'),
            'source' => $this->hasPricingOrigin() ? 'area_pricing_origin' : 'area_center',
        ];
    }
}
