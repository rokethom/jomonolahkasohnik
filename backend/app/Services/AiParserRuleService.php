<?php

namespace App\Services;

use App\Models\AiParserRule;

class AiParserRuleService
{
    public function find(string $text): ?AiParserRule
    {
        return AiParserRule::query()
            ->where('text_hash', $this->hash($text))
            ->where('is_active', true)
            ->first();
    }

    public function remember(string $text, array $aiData, ?string $provider = null, ?string $model = null): ?AiParserRule
    {
        $serviceType = $this->serviceType($aiData);
        if ($serviceType === null) {
            return null;
        }

        return AiParserRule::query()->updateOrCreate(
            ['text_hash' => $this->hash($text)],
            [
                'normalized_text' => $this->normalize($text),
                'service_type' => $serviceType,
                'ai_data' => $this->sanitizeAiData($aiData),
                'example_text' => $text,
                'provider' => $provider,
                'model' => $model,
                'is_active' => true,
            ],
        );
    }

    public function markUsed(AiParserRule $rule): void
    {
        $rule->forceFill([
            'hit_count' => $rule->hit_count + 1,
            'last_used_at' => now(),
        ])->save();
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/u', "\n", $text) ?? $text;

        return trim($text);
    }

    private function hash(string $text): string
    {
        return hash('sha256', $this->normalize($text));
    }

    private function serviceType(array $aiData): ?string
    {
        $serviceType = trim((string) ($aiData['service_type'] ?? ''));

        return $serviceType === '' || strtolower($serviceType) === 'null' ? null : $serviceType;
    }

    private function sanitizeAiData(array $aiData): array
    {
        return collect($aiData)
            ->only([
                'service_type',
                'pickup_address',
                'destination_address',
                'store_location',
                'purchase_address',
                'items',
                'passengers',
                'notes',
                'missing_fields',
            ])
            ->all();
    }
}
