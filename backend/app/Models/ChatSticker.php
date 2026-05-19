<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSticker extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'image_path',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getImageUrlAttribute(): string
    {
        return '/api/media/'.ltrim($this->image_path, '/');
    }
}
