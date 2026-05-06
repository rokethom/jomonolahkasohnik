<?php

namespace App\Models;

use App\Services\PricingKeywordRuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingKeywordRule extends Model
{
    use HasFactory;

    public const SERVICE_SCOPES = [
        'all' => 'All services',
        'ojek' => 'Ojek',
        'kurir' => 'Kurir',
        'delivery' => 'Delivery Order',
        'belanja' => 'Belanja',
        'gift_order' => 'Gift Order',
        'travel' => 'Travel',
        'joker_mobil' => 'Joker Mobil',
    ];

    protected $fillable = [
        'name',
        'keywords',
        'amount',
        'service_scopes',
        'is_active',
        'priority',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'service_scopes' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (PricingKeywordRule $rule): void {
            $rule->keywords = app(PricingKeywordRuleService::class)->normalizeKeywordList((string) $rule->keywords);
            $rule->service_scopes = app(PricingKeywordRuleService::class)->normalizeScopes($rule->service_scopes);
        });

        static::saved(fn (): mixed => app(PricingKeywordRuleService::class)->clearCache());
        static::deleted(fn (): mixed => app(PricingKeywordRuleService::class)->clearCache());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
