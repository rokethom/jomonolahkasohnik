<?php

namespace App\Services;

use App\Models\Service;
use InvalidArgumentException;

class ServiceParserService
{
    private const SERVICE_ALIASES = [
        'DO' => 'DO',
        'DELIVERY' => 'DO',
        'KR' => 'KR',
        'KURIR' => 'KR',
        'GO' => 'GO',
        'GIFT' => 'GO',
        'OJ' => 'OJ',
        'OJEK' => 'OJ',
        'BL' => 'BL',
        'BELANJA' => 'BL',
        'TV' => 'TV',
        'TRAVEL' => 'TV',
        'JM' => 'JM',
        'JOKER' => 'JM',
    ];

    public function __construct(private readonly PricingParser $pricingParser)
    {
    }

    public function parse(string $rawText): array
    {
        $lines = collect(preg_split('/\R/', trim($rawText)) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Teks request tidak boleh kosong.');
        }

        $code = $this->parseServiceCode($lines->first());
        $service = Service::query()->where('code', $code)->where('is_active', true)->first();

        if (! $service) {
            throw new InvalidArgumentException('Kode layanan tidak valid atau tidak aktif.');
        }

        $price = $this->pricingParser->parse($rawText);
        if ($price === null) {
            throw new InvalidArgumentException('Harga jasa tidak ditemukan. Gunakan format seperti 7k atau 15.5k.');
        }

        return [
            'service' => $service,
            'service_code' => $service->code,
            'service_type' => $service->name,
            'price' => $price,
            'pickup_address' => $lines->get(1, 'Driver request'),
            'destination_address' => $this->parseDestination($lines->all()),
            'notes' => $rawText,
        ];
    }

    private function parseServiceCode(string $line): string
    {
        $token = strtoupper(trim(str($line)->before(' ')->toString()));

        return self::SERVICE_ALIASES[$token] ?? $token;
    }

    private function parseDestination(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/\bke\s+(.+)/i', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return $lines[2] ?? 'Driver request destination';
    }
}
