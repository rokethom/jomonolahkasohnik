<?php

namespace App\Services;

class KeywordDetector
{
    private const SERVICE_KEYWORDS = [
        'DO' => ['beli', 'belikan', 'pesan'],
        'kurir' => ['antar'],
        'ojek' => ['ojek'],
    ];

    public function detectService(string $text): ?string
    {
        $normalized = $this->normalize($text);

        foreach (self::SERVICE_KEYWORDS as $serviceType => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/(?:^|[^\pL\pN])'.preg_quote($keyword, '/').'(?:[^\pL\pN]|$)/u', $normalized) === 1) {
                    return $serviceType;
                }
            }
        }

        return null;
    }

    public function firstServiceKeyword(string $text): ?string
    {
        $normalized = $this->normalize($text);

        foreach (array_merge(...array_values(self::SERVICE_KEYWORDS)) as $keyword) {
            if (preg_match('/(?:^|[^\pL\pN])('.preg_quote($keyword, '/').')(?:[^\pL\pN]|$)/u', $normalized, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }
}
