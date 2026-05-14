<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLocationSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'area_id',
        'location_poi_id',
        'location_text',
        'normalized_text',
        'role',
        'service_type',
        'aliases',
        'raw_text',
        'example_payload',
        'latitude',
        'longitude',
        'occurrence_count',
        'confidence',
        'status',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'area_id' => 'integer',
        'location_poi_id' => 'integer',
        'aliases' => 'array',
        'example_payload' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'occurrence_count' => 'integer',
        'confidence' => 'integer',
    ];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function locationPoi(): BelongsTo
    {
        return $this->belongsTo(LocationPoi::class);
    }
}
