<?php

namespace App\Services;

class ItemExtractor
{
    public function extract(string $text): array
    {
        $block = $this->belikanBlock($text);
        if ($block === '') {
            return [];
        }

        return collect(preg_split('/\R|,/u', $block) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->map(fn (string $line): ?array => $this->parseLine($line))
            ->filter()
            ->values()
            ->all();
    }

    private function belikanBlock(string $text): string
    {
        if (preg_match('/(?:belikan|pembelian|pesanan|order)\s*:\s*(.*?)(?:\R\s*(?:alamat\s+pembelian|alamat|area)\s*:|$)/isu', $text, $match) !== 1) {
            return '';
        }

        return trim($match[1]);
    }

    private function parseLine(string $line): ?array
    {
        $line = preg_replace('/^(?:[-*]\s*|[a-z0-9]+\s*[:.)-]\s*)/iu', '', $line) ?? $line;
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $quantity = 1;
        if (preg_match('/^(\d+)\s*(?:x|pcs?|porsi|portions?|bungkus|buah)?\s+(.+)$/iu', $line, $match) === 1) {
            $quantity = max(1, (int) $match[1]);
            $line = trim($match[2]);
        }

        if (preg_match('/\s+(\d+)\s*$/u', $line, $match) === 1) {
            $quantity = max(1, (int) $match[1]);
            $line = trim(substr($line, 0, -strlen($match[0])));
        }

        if (preg_match('/\s+(\d+)\s*(?:x|pcs?|porsi|portions?|bungkus|buah|gelas|botol|pack|kotak)\s*$/iu', $line, $match) === 1) {
            $quantity = max(1, (int) $match[1]);
            $line = trim(substr($line, 0, -strlen($match[0])));
        }

        return [
            'name' => $this->normalizeName($line),
            'quantity' => $quantity,
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        $lower = mb_strtolower($name);

        return mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1);
    }
}
