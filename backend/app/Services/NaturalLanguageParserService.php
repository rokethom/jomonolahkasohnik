<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;

class NaturalLanguageParserService
{
    public function __construct(
        private readonly KeywordDetector $keywords,
        private readonly AddressExtractor $addresses,
    ) {
    }

    public function parse(User $user, string $text): ?array
    {
        $serviceType = $this->keywords->detectService($text);
        if (! $serviceType) {
            return null;
        }

        if ($serviceType !== 'DO') {
            return null;
        }

        $destination = $this->addresses->destination($text, $user);
        $pickup = $this->pickupAddress($text);
        $storeLocation = $this->addresses->storeLocation($text);
        $items = $this->items($text);
        $passengers = $this->passengers($text);

        if ($serviceType === 'DO' && ($items === [] || ! $storeLocation)) {
            return null;
        }

        if ($serviceType === 'DO' && ! $destination) {
            return null;
        }

        if ($serviceType === 'kurir' && (! $storeLocation || ! $destination)) {
            return null;
        }

        if ($serviceType === 'ojek' && ! $destination) {
            return null;
        }

        if ($serviceType === 'joker_mobil' && ! $destination) {
            return null;
        }

        $branch = $this->branch($user);
        $userLat = (float) ($user->lat ?: $user->currentLocation?->lat ?: $branch?->latitude ?: -7.7063);
        $userLng = (float) ($user->lng ?: $user->currentLocation?->lng ?: $branch?->longitude ?: 114.0098);
        $pickupLat = $serviceType === 'DO' ? $userLat + 0.01 : $userLat;
        $pickupLng = $serviceType === 'DO' ? $userLng + 0.01 : $userLng;
        $pickupAddress = $serviceType === 'DO' ? $storeLocation : ($pickup ?: $this->addresses->profileAddress($user));
        $destinationAddress = $destination ?: ($serviceType === 'DO' ? null : $this->addresses->profileAddress($user));
        $servicePayload = [
            'source' => 'smart_parser',
            'raw_text' => $text,
            'store_location' => $storeLocation,
            'passengers' => $passengers,
            'customer' => [
                'name' => $user->name,
                'phone' => $user->phone,
            ],
        ];

        return [
            'service_type' => $serviceType,
            'customer_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'address' => $this->addresses->profileAddress($user),
            'items' => $items,
            'store_location' => $storeLocation,
            'destination' => $destinationAddress,
            'customer' => [
                'name' => $user->name,
                'phone' => $user->phone,
            ],
            'passengers' => $passengers,
            'stops' => [],
            'branch' => $branch,
            'payload' => [
                'service_type' => $serviceType,
                'pickup_address' => $pickupAddress ?: 'Lokasi jemput',
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $destinationAddress,
                'destination_lat' => $userLat,
                'destination_lng' => $userLng,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $destinationAddress,
                'notes' => $this->notes($text, $items, $storeLocation, $destinationAddress),
                'service_payload' => $servicePayload,
                'items' => $items,
                'points' => [],
            ],
        ];
    }

    private function items(string $text): array
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));

        if (preg_match('/\b(?:beli|belikan|pesan)\s+(.+?)(?=\s+\b(?:di|dari|dan|kirim|antar|ke|pak|bu|toko|warung|resto|rumah\s+makan)\b|$)/u', $normalized, $match) !== 1) {
            return [];
        }

        $name = trim($match[1], " \t\n\r\0\x0B.,");
        if ($name === '') {
            return [];
        }

        return [[
            'name' => $name,
            'qty' => 1,
            'quantity' => 1,
        ]];
    }

    private function notes(string $text, array $items, ?string $storeLocation, string $destination): string
    {
        return trim(implode("\n", array_filter([
            'Smart parser input: '.$text,
            $items !== [] ? 'Item: '.collect($items)->pluck('name')->implode(', ') : null,
            $storeLocation ? 'Lokasi pembelian: '.$storeLocation : null,
            'Tujuan: '.$destination,
        ])));
    }

    private function pickupAddress(string $text): ?string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));

        if (preg_match('/(?:alamat\s+jemput|jemput\s+di|jemput|dari)\s+(.+?)\s+(?:alamat\s+antar|antar\s+ke|tujuan|ke)\s+.+$/u', $normalized, $match) === 1) {
            return $this->cleanAddress($match[1]);
        }

        return null;
    }

    private function passengers(string $text): int
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));

        if (preg_match('/(?:jumlah\s+)?penumpang\s*:?\s*(\d+)/u', $normalized, $match) === 1) {
            return max(1, (int) $match[1]);
        }

        if (preg_match('/(\d+)\s*(?:orang|penumpang)/u', $normalized, $match) === 1) {
            return max(1, (int) $match[1]);
        }

        return 1;
    }

    private function cleanAddress(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+(?:jumlah\s+)?penumpang\s*:?\s*\d+.*$/iu', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B.,");
    }

    private function branch(User $user): ?Branch
    {
        if ($user->branch_id) {
            return Branch::query()->find($user->branch_id);
        }

        return Branch::query()->whereNotNull('latitude')->whereNotNull('longitude')->first();
    }
}
