<?php

namespace App\Services;

class PricingParser
{
    public function parse(?string $text): ?int
    {
        if (! preg_match('/(\d+(?:[\.,]\d+)?)\s*k\b/i', (string) $text, $matches)) {
            return null;
        }

        $number = (float) str_replace(',', '.', $matches[1]);

        return (int) round($number * 1000);
    }
}
