<?php

namespace App\Models;

use App\Services\SettingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(fn (AppSetting $setting): bool => app(SettingService::class)->clearCache());
        static::deleted(fn (AppSetting $setting): bool => app(SettingService::class)->clearCache());
    }
}
