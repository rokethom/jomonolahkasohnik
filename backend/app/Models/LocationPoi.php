<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class LocationPoi extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'area_id',
        'geojson_region_id',
        'name',
        'aliases',
        'category',
        'latitude',
        'longitude',
        'source',
        'confidence',
        'priority',
        'hit_count',
        'last_used_at',
        'is_active',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'area_id' => 'integer',
        'geojson_region_id' => 'integer',
        'aliases' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'confidence' => 'integer',
        'priority' => 'integer',
        'hit_count' => 'integer',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn (): bool => Cache::flush());
        static::deleted(fn (): bool => Cache::flush());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function geojsonRegion(): BelongsTo
    {
        return $this->belongsTo(GeojsonRegion::class);
    }
}
