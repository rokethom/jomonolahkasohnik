<?php

namespace App\Support;

class ServiceTypeNormalizer
{
    /**
     * @var array<string, string>
     */
    private const CODE_TO_TYPE = [
        'OJ' => 'ojek',
        'KR' => 'kurir',
        'DO' => 'delivery',
        'BL' => 'belanja',
        'GO' => 'gift_order',
        'JM' => 'joker_mobil',
        'TV' => 'travel',
        'TR' => 'travel',
        'PJ' => 'pajak_tahunan_kendaraan',
        'PG' => 'layanan_pengaduan',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_TO_CODE = [
        'oj' => 'OJ',
        'ojek' => 'OJ',
        'kurir' => 'KR',
        'kr' => 'KR',
        'do' => 'DO',
        'delivery' => 'DO',
        'belanja' => 'BL',
        'bl' => 'BL',
        'gift' => 'GO',
        'gift_order' => 'GO',
        'gift order' => 'GO',
        'go' => 'GO',
        'joker' => 'JM',
        'joker_mobil' => 'JM',
        'joker mobil' => 'JM',
        'joker-mobile' => 'JM',
        'jm' => 'JM',
        'travel' => 'TR',
        'tv' => 'TR',
        'tr' => 'TR',
        'pajak_tahunan_kendaraan' => 'PJ',
        'pajak tahunan kendaraan' => 'PJ',
        'pj' => 'PJ',
        'layanan_pengaduan' => 'PG',
        'layanan pengaduan' => 'PG',
        'pengaduan' => 'PG',
        'pg' => 'PG',
    ];

    public static function type(string $service): string
    {
        $value = self::key($service);
        if ($value === '') {
            return '';
        }

        $upper = strtoupper($value);
        if (isset(self::CODE_TO_TYPE[$upper])) {
            return self::CODE_TO_TYPE[$upper];
        }

        return match ($value) {
            'gift', 'gift order' => 'gift_order',
            'joker mobil', 'joker-mobile', 'joker' => 'joker_mobil',
            'do' => 'delivery',
            'oj' => 'ojek',
            'kr' => 'kurir',
            'bl' => 'belanja',
            default => str_replace([' ', '-'], '_', $value),
        };
    }

    public static function code(string $service): string
    {
        $value = self::key($service);
        if ($value === '') {
            return '';
        }

        if (isset(self::TYPE_TO_CODE[$value])) {
            return self::TYPE_TO_CODE[$value];
        }

        $upper = strtoupper($value);
        if (isset(self::CODE_TO_TYPE[$upper])) {
            return $upper;
        }

        return strtoupper(str_replace([' ', '-'], '_', $value));
    }

    /**
     * @param array<int, mixed> $services
     * @return array<int, string>
     */
    public static function codes(array $services): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $service): string => self::code((string) $service),
            $services,
        ))));
    }

    private static function key(string $service): string
    {
        return strtolower(trim($service));
    }
}
