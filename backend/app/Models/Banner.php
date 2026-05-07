<?php

namespace App\Models;

use App\Models\Concerns\HasActiveDateRange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasActiveDateRange;
    use HasFactory;

    protected $fillable = [
        'title',
        'image',
        'link',
        'is_active',
        'order',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'image_thumb_url',
    ];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    public function getImageThumbUrlAttribute(): ?string
    {
        return $this->image_url;
    }
}
