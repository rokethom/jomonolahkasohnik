<?php

namespace App\Models;

use App\Models\Concerns\HasActiveDateRange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class HomeItem extends Model implements HasMedia
{
    use HasActiveDateRange;
    use HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'section_id',
        'title',
        'subtitle',
        'image',
        'icon',
        'link',
        'extra_data',
        'is_active',
        'order',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'is_active' => 'boolean',
        'order' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'image_thumb_url',
        'icon_url',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(HomeSection::class, 'section_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->singleFile();
        $this->addMediaCollection('icon')->singleFile();
    }

    public function registerMediaConversions(Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(320)
            ->height(180)
            ->quality(78)
            ->nonQueued();
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('image') ?: ($this->image ? url('/api/media/'.ltrim($this->image, '/')) : null);
    }

    public function getImageThumbUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('image', 'thumb') ?: $this->image_url;
    }

    public function getIconUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('icon') ?: ($this->icon ? url('/api/media/'.ltrim($this->icon, '/')) : null);
    }
}
