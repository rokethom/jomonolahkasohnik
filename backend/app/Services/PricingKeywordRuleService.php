<?php

namespace App\Services;

use App\Models\PricingKeywordRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PricingKeywordRuleService
{
    private const CACHE_KEY = 'pricing_keyword_rules.active';

    public function activeRules(): Collection
    {
        if (! Schema::hasTable('pricing_keyword_rules')) {
            return collect();
        }

        return Cache::rememberForever(self::CACHE_KEY, fn (): Collection => PricingKeywordRule::query()
            ->active()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get());
    }

    public function clearCache(): bool
    {
        return Cache::forget(self::CACHE_KEY);
    }

    public function calculate(string $serviceType, array|string|null $text): ?array
    {
        $rules = $this->activeRules();

        if ($rules->isEmpty()) {
            return null;
        }

        $haystack = mb_strtolower($this->textFromMixed($text));
        $serviceType = $this->normalizeServiceType($serviceType);
        $matches = [];
        $total = 0;

        foreach ($rules as $rule) {
            if (! $this->scopeMatches($serviceType, $rule->service_scopes)) {
                continue;
            }

            $matchedKeyword = $this->matchedKeyword($haystack, (string) $rule->keywords);
            if ($matchedKeyword === null) {
                continue;
            }

            $amount = (int) $rule->amount;
            $total += $amount;
            $matches[] = [
                'id' => $rule->id,
                'name' => $rule->name,
                'keyword' => $matchedKeyword,
                'amount' => $amount,
                'service_scopes' => $rule->service_scopes ?: ['all'],
            ];
        }

        return [
            'amount' => $total,
            'matches' => $matches,
            'uses_database_rules' => true,
        ];
    }

    public function normalizeKeywordList(string $keywords): string
    {
        return collect(explode(',', mb_strtolower($keywords)))
            ->map(fn (string $keyword): string => trim(preg_replace('/\s+/u', ' ', $keyword) ?? ''))
            ->filter()
            ->unique()
            ->implode(', ');
    }

    public function normalizeScopes(mixed $scopes): ?array
    {
        $values = collect(is_array($scopes) ? $scopes : [])
            ->map(fn (mixed $scope): string => $this->normalizeServiceType((string) $scope))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($values === [] || in_array('all', $values, true)) {
            return null;
        }

        return $values;
    }

    public function hardcodedRules(): array
    {
        return [
            [
                'name' => 'Rumah Sakit',
                'keywords' => ['rs'],
                'amount' => 2000,
                'service_scopes' => ['all'],
                'source_fields' => ['destination_text', 'destination_address', 'notes', 'service_payload', 'items'],
                'description' => 'Keyword RS memakai match kata utuh agar tidak salah membaca teks seperti generated.',
            ],
            [
                'name' => 'Gacoan Purchase Charge',
                'keywords' => ['gacoan'],
                'amount' => 2000,
                'service_scopes' => ['belanja', 'gift_order', 'kurir'],
                'source_fields' => ['destination_text', 'destination_address', 'notes', 'service_payload', 'items'],
                'description' => 'Tambahan untuk layanan pembelian/pengiriman tertentu.',
            ],
            [
                'name' => 'Area Purchase Charge',
                'keywords' => ['pasar', 'roxy', 'royal', 'swalayan'],
                'amount' => 3000,
                'service_scopes' => ['belanja', 'gift_order', 'kurir'],
                'source_fields' => ['destination_text', 'destination_address', 'notes', 'service_payload', 'items'],
                'description' => 'Hardcoded lama hanya mengenakan satu charge area meski beberapa keyword area muncul sekaligus.',
            ],
        ];
    }

    public function preview(string $serviceType, string $text): array
    {
        $database = $this->calculate($serviceType, $text);

        if ($database !== null) {
            return [
                ...$database,
                'mode' => 'database',
            ];
        }

        $serviceType = $this->normalizeServiceType($serviceType);
        $haystack = mb_strtolower($text);
        $matches = [];
        $total = 0;

        foreach ($this->hardcodedRules() as $rule) {
            if (! $this->scopeMatches($serviceType, $rule['service_scopes'])) {
                continue;
            }

            foreach ($rule['keywords'] as $keyword) {
                if (! $this->keywordMatches($haystack, $keyword)) {
                    continue;
                }

                $matches[] = [
                    'name' => $rule['name'],
                    'keyword' => $keyword,
                    'amount' => $rule['amount'],
                    'service_scopes' => $rule['service_scopes'],
                ];
                $total += (int) $rule['amount'];
                break;
            }
        }

        return [
            'amount' => $total,
            'matches' => $matches,
            'uses_database_rules' => false,
            'mode' => 'hardcoded_fallback',
        ];
    }

    private function matchedKeyword(string $haystack, string $keywords): ?string
    {
        foreach (explode(',', $keywords) as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && $this->keywordMatches($haystack, $keyword)) {
                return $keyword;
            }
        }

        return null;
    }

    private function keywordMatches(string $haystack, string $keyword): bool
    {
        if ($keyword === 'rs') {
            return preg_match('/(^|[^\pL\pN])rs([^\pL\pN]|$)/u', $haystack) === 1;
        }

        return str_contains($haystack, $keyword);
    }

    private function scopeMatches(string $serviceType, ?array $scopes): bool
    {
        if ($scopes === null || $scopes === [] || in_array('all', $scopes, true)) {
            return true;
        }

        return in_array($this->normalizeServiceType($serviceType), $scopes, true);
    }

    private function normalizeServiceType(string $serviceType): string
    {
        return match (strtolower(trim($serviceType))) {
            'do' => 'delivery',
            'gift order', 'gift' => 'gift_order',
            'joker mobil', 'joker-mobile', 'joker' => 'joker_mobil',
            default => strtolower(trim($serviceType)),
        };
    }

    private function textFromMixed(array|string|null $text): string
    {
        if ($text === null) {
            return '';
        }

        if (is_string($text)) {
            return $text;
        }

        return collect($text)
            ->flatten()
            ->map(fn ($value): string => is_scalar($value) ? (string) $value : '')
            ->implode(' ');
    }
}
