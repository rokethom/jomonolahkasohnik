<?php

namespace App\Services;

class OrderTextNormalizer
{
    /**
     * Keep this dictionary conservative. It should make customer slang easier to parse
     * without changing the user's actual intent.
     *
     * @return array<string, string>
     */
    private function replacements(): array
    {
        return [
            'aq' => 'aku',
            'ak' => 'aku',
            'sy' => 'saya',
            'sya' => 'saya',
            'gw' => 'saya',
            'gue' => 'saya',
            'gua' => 'saya',
            'min' => 'admin',
            'cs' => 'operator',
            'op' => 'operator',
            'plg' => 'pelanggan',
            'cust' => 'customer',
            'cus' => 'customer',
            'org' => 'orang',
            'orng' => 'orang',
            'penumpng' => 'penumpang',
            'pnumpang' => 'penumpang',
            'jempt' => 'jemput',
            'jmpt' => 'jemput',
            'jemputin' => 'jemput',
            'jemputkan' => 'jemput',
            'antr' => 'antar',
            'anter' => 'antar',
            'anterin' => 'antar',
            'antarkan' => 'antar',
            'kirimkan' => 'kirim',
            'krm' => 'kirim',
            'krimin' => 'kirim',
            'belikno' => 'belikan',
            'belikne' => 'belikan',
            'belikanin' => 'belikan',
            'blikan' => 'belikan',
            'bli' => 'beli',
            'beliin' => 'beli',
            'pesenin' => 'pesan',
            'psn' => 'pesan',
            'psen' => 'pesan',
            'pesen' => 'pesan',
            'pesankan' => 'pesan',
            'mkn' => 'makan',
            'mknn' => 'makanan',
            'brg' => 'barang',
            'dok' => 'dokumen',
            'paketn' => 'paket',
            'rmh' => 'rumah',
            'rmah' => 'rumah',
            'alamatq' => 'alamat saya',
            'almtsaya' => 'alamat saya',
            'almt' => 'alamat',
            'jl' => 'jalan',
            'jln' => 'jalan',
            'gg' => 'gang',
            'no' => 'nomor',
            'nmr' => 'nomor',
            'hp' => 'telepon',
            'wa' => 'whatsapp',
            'waq' => 'whatsapp saya',
            'ojol' => 'ojek',
            'ojolan' => 'ojek',
            'ojk' => 'ojek',
            'ojeg' => 'ojek',
            'mtr' => 'motor',
            'motr' => 'motor',
            'mbl' => 'mobil',
            'mob' => 'mobil',
            'citi car' => 'citycar',
            'ctycar' => 'citycar',
            'tf' => 'transfer',
            'trf' => 'transfer',
            'xfer' => 'transfer',
            'cashh' => 'cash',
            'qrisnya' => 'qris',
            'qrisx' => 'qris',
            'skrg' => 'sekarang',
            'skrng' => 'sekarang',
            'nnti' => 'nanti',
            'ntar' => 'nanti',
            'skalian' => 'sekalian',
            'sekalianin' => 'sekalian',
            'bs' => 'bisa',
            'bsa' => 'bisa',
            'gk' => 'tidak',
            'ga' => 'tidak',
            'nggak' => 'tidak',
            'ndak' => 'tidak',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function numberWords(): array
    {
        return [
            'satu' => 1,
            'dua' => 2,
            'tiga' => 3,
            'empat' => 4,
            'lima' => 5,
            'enam' => 6,
            'tujuh' => 7,
            'delapan' => 8,
            'sembilan' => 9,
            'sepuluh' => 10,
        ];
    }

    public function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);
        $normalized = preg_replace('/[^\S\n]+/u', ' ', $normalized) ?? $normalized;
        $normalized = $this->removeSpeechRepeats($normalized);
        $normalized = $this->normalizeMoney($normalized);
        $normalized = $this->normalizeNumberWords($normalized);

        foreach ($this->replacements() as $from => $to) {
            $normalized = preg_replace('/(?<![\pL\pN])'.preg_quote($from, '/').'(?![\pL\pN])/u', $to, $normalized) ?? $normalized;
        }

        $normalized = preg_replace('/\b(?:dri|dr)\b/u', 'dari', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:k[e3]|tujuan)\b/u', 'ke', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:d|di)\s+(?=\S)/u', 'di ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:jml|jumlahnya)\b/u', 'jumlah', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:pnp|penumpangx)\b/u', 'penumpang', $normalized) ?? $normalized;
        $normalized = preg_replace('/[ \t]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{3,}/u', "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array<int, string>
     */
    public function detectedAliases(string $text): array
    {
        $raw = mb_strtolower($text);

        return collect($this->replacements())
            ->filter(fn (string $to, string $from): bool => preg_match('/(?<![\pL\pN])'.preg_quote($from, '/').'(?![\pL\pN])/u', $raw) === 1)
            ->map(fn (string $to, string $from): string => $from.' => '.$to)
            ->values()
            ->all();
    }

    private function normalizeMoney(string $text): string
    {
        return preg_replace_callback('/\b(\d+(?:[.,]\d+)?)\s*(k|rb|ribu)\b/u', function (array $match): string {
            $number = (float) str_replace(',', '.', $match[1]);

            return (string) ((int) round($number * 1000));
        }, $text) ?? $text;
    }

    private function normalizeNumberWords(string $text): string
    {
        foreach ($this->numberWords() as $word => $number) {
            $text = preg_replace('/(?<![\pL\pN])'.preg_quote($word, '/').'(?![\pL\pN])/u', (string) $number, $text) ?? $text;
        }

        return $text;
    }

    private function removeSpeechRepeats(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [$text];

        return collect($lines)
            ->map(function (string $line): string {
                $words = preg_split('/\s+/u', trim($line)) ?: [];
                $words = array_values(array_filter($words, fn (string $word): bool => $word !== ''));
                if ($words === []) {
                    return '';
                }

                $words = array_values(array_filter($words, fn (string $word, int $index): bool => $index === 0 || mb_strtolower($word) !== mb_strtolower($words[$index - 1] ?? ''), ARRAY_FILTER_USE_BOTH));

                $changed = true;
                while ($changed) {
                    $changed = false;
                    for ($size = min(10, intdiv(count($words), 2)); $size >= 2; $size--) {
                        for ($index = 0; $index <= count($words) - ($size * 2); $index++) {
                            $first = mb_strtolower(implode(' ', array_slice($words, $index, $size)));
                            $second = mb_strtolower(implode(' ', array_slice($words, $index + $size, $size)));
                            if ($first !== $second) {
                                continue;
                            }

                            array_splice($words, $index + $size, $size);
                            $changed = true;
                            $index = max(-1, $index - $size);
                        }
                    }
                }

                return implode(' ', $words);
            })
            ->implode("\n");
    }
}
