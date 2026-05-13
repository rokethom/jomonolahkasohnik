<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeojsonRegion extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'area_id', 'name', 'geojson', 'geometry_type', 'coordinates',
        'centroid_lat', 'centroid_lng', 'min_lat', 'max_lat', 'min_lng', 'max_lng',
        'version', 'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'area_id' => 'integer',
        'geojson' => 'array',
        'coordinates' => 'array',
        'centroid_lat' => 'decimal:8',
        'centroid_lng' => 'decimal:8',
        'min_lat' => 'decimal:8',
        'max_lat' => 'decimal:8',
        'min_lng' => 'decimal:8',
        'max_lng' => 'decimal:8',
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeContainingBoundingBox(Builder $query, float $lat, float $lng): Builder
    {
        return $query
            ->where('min_lat', '<=', $lat)
            ->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)
            ->where('max_lng', '>=', $lng);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
