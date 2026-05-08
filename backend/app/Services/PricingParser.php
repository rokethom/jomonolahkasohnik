<?php

namespace App\Services;

class PricingParser
{
    public function parse(?string $text): ?int
    {
        return $this->parseAll($text)[0] ?? null;
    }

    public function parseLast(?string $text): ?int
    {
        $prices = $this->parseAll($text);

        return $prices === [] ? null : $prices[array_key_last($prices)];
    }

    /**
     * @return array<int, int>
     */
    public function parseAll(?string $text): array
    {
        if (! preg_match_all('/(\d+(?:[\.,]\d+)?)\s*k\b/i', (string) $text, $matches)) {
            return [];
        }

        return array_map(
            fn (string $number): int => (int) round(((float) str_replace(',', '.', $number)) * 1000),
            $matches[1],
        );
    }
}
