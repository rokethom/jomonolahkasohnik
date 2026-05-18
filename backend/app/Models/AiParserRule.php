<?php

namespace App\Models;

use App\Services\OrderTextNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiParserRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'text_hash',
        'normalized_text',
        'service_type',
        'ai_data',
        'example_text',
        'provider',
        'model',
        'is_active',
        'hit_count',
        'last_used_at',
    ];

    protected $casts = [
        'ai_data' => 'array',
        'is_active' => 'boolean',
        'hit_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (AiParserRule $rule): void {
            $sourceText = (string) ($rule->normalized_text ?: $rule->example_text);
            $normalized = app(OrderTextNormalizer::class)->normalize($sourceText);

            if ($rule->normalized_text === null || trim((string) $rule->normalized_text) === '' || $rule->isDirty('example_text')) {
                $rule->normalized_text = $normalized;
            }

            if ($rule->text_hash === null || trim((string) $rule->text_hash) === '' || $rule->isDirty('normalized_text') || $rule->isDirty('example_text')) {
                $rule->text_hash = hash('sha256', $rule->normalized_text);
            }

            $rule->service_type = trim((string) $rule->service_type);
            $rule->provider = $rule->provider ?: 'manual_cms';
            $rule->model = $rule->model ?: 'cms';

            $aiData = is_array($rule->ai_data) ? $rule->ai_data : [];
            $aiData['service_type'] = $aiData['service_type'] ?? $rule->service_type;
            $rule->ai_data = $aiData;
        });
    }
}
