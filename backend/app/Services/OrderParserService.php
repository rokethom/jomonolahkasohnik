<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;

class OrderParserService
{
    public function __construct(
        private readonly ItemExtractor $items,
        private readonly NaturalLanguageParserService $naturalLanguage,
        private readonly AiOrderParserService $aiParser,
        private readonly OrderTextNormalizer $normalizer,
    ) {
    }

    public function parse(User $user, string $text): ?array
    {
        $aiParsed = $this->aiParser->parse($user, $text);
        if ($aiParsed !== null) {
            return $aiParsed;
        }

        $normalizedText = $this->normalizer->normalize($text);
        $natural = $this->naturalLanguage->parse($user, $normalizedText);
        if ($natural !== null) {
            $natural['payload']['service_payload']['raw_text'] = $text;
            $natural['payload']['service_payload']['normalized_text'] = $normalizedText;
            return $natural;
        }

        $items = $this->items->extract($normalizedText);
        $storeLocation = $this->storeLocation($normalizedText);
        $serviceType = $this->detectService($normalizedText);
        if (! $serviceType && $items !== [] && $storeLocation) {
            $serviceType = 'DO';
        }

        if (! $serviceType) {
            return null;
        }

        $branch = $this->branch($user);
        $profileAddress = $this->profileAddress($user, $branch);
        $pickupLat = (float) ($branch?->latitude ?: -6.9219);
        $pickupLng = (float) ($branch?->longitude ?: 107.6071);

        if ($serviceType === 'kurir') {
            return $this->parseCourier($user, $normalizedText, $branch, $profileAddress, $pickupLat, $pickupLng, $text);
        }

        if (in_array($serviceType, ['ojek', 'joker_mobil'], true)) {
            return $this->parseOjek($user, $normalizedText, $branch, $profileAddress, $pickupLat, $pickupLng, $text);
        }

        if ($serviceType === 'gift_order') {
            return $this->parseGiftOrder($user, $normalizedText, $branch, $profileAddress, $pickupLat, $pickupLng, $text);
        }

        if ($items === [] || ! $storeLocation) {
            return null;
        }

        return [
            'service_type' => $serviceType,
            'customer_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'address' => $profileAddress,
            'items' => $items,
            'store_location' => $storeLocation,
            'stops' => [],
            'branch' => $branch,
            'payload' => [
                'service_type' => $serviceType,
                'pickup_address' => $storeLocation,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $profileAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $profileAddress,
                'notes' => $text,
                'service_payload' => [
                    'store_location' => $storeLocation,
                    'location_flow_note' => 'Alamat pembelian dipakai sebagai titik ambil barang; alamat profile customer dipakai sebagai tujuan antar.',
                    'source' => 'smart_parser',
                    'normalized_text' => $normalizedText,
                ],
                'items' => $items,
                'points' => [],
            ],
        ];
    }

    private function detectService(string $text): ?string
    {
        if (preg_match('/joker\s+mobil|(?:^|\W)mobil(?:\W|$)|citycar/iu', $text) === 1) {
            return 'joker_mobil';
        }

        if (preg_match('/pesanan\s+kurir|(?:^|\W)kurir(?:\W|$)/iu', $text) === 1) {
            return 'kurir';
        }

        if (preg_match('/pesanan\s+ojek|(?:^|\W)ojek(?:\W|$)/iu', $text) === 1) {
            return 'ojek';
        }

        if (preg_match('/gift\s+order|pesanan\s+gift|(?:^|\W)(?:gift|kado|hadiah)(?:\W|$)/iu', $text) === 1) {
            return 'gift_order';
        }

        return preg_match('/delivery\s+order|(?:^|\W)do(?:\W|$)/iu', $text) === 1 ? 'DO' : null;
    }

    private function parseCourier(User $user, string $text, ?Branch $branch, string $profileAddress, float $pickupLat, float $pickupLng, ?string $rawText = null): ?array
    {
        $receiverBlock = $this->afterMarker($text, 'Antarkan barang ke');
        $receiverName = $this->field($receiverBlock, 'nama');
        $receiverPhone = $this->field($receiverBlock, '(?:hp|whatsapp|wa)(?:\s*\/\s*(?:hp|whatsapp|wa))*');
        $receiverAddress = $this->field($receiverBlock, 'alamat');
        $itemType = $this->field($text, 'jenis\s+barang');
        $price = $this->field($text, 'harga');

        if (! $receiverName || ! $receiverPhone || ! $receiverAddress || ! $itemType) {
            return null;
        }

        return [
            'service_type' => 'kurir',
            'customer_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'address' => $profileAddress,
            'items' => [['name' => $itemType, 'quantity' => 1, 'price' => (int) preg_replace('/\D/u', '', (string) $price)]],
            'store_location' => $receiverAddress,
            'receiver' => [
                'name' => $receiverName,
                'phone' => $receiverPhone,
                'address' => $receiverAddress,
            ],
            'stops' => [],
            'branch' => $branch,
            'payload' => [
                'service_type' => 'kurir',
                'pickup_address' => $profileAddress,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $receiverAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $receiverAddress,
                'notes' => $rawText ?: $text,
                'service_payload' => [
                    'receiver' => compact('receiverName', 'receiverPhone', 'receiverAddress'),
                    'item_type' => $itemType,
                    'item_price_text' => $price,
                    'source' => 'smart_parser',
                    'normalized_text' => $text,
                ],
                'items' => [['name' => $itemType, 'quantity' => 1, 'price' => (int) preg_replace('/\D/u', '', (string) $price)]],
                'points' => [],
            ],
        ];
    }

    private function parseOjek(User $user, string $text, ?Branch $branch, string $profileAddress, float $pickupLat, float $pickupLng, ?string $rawText = null): ?array
    {
        [$loosePickup, $looseDestination] = $this->routeAddresses($text);
        $pickupAddress = $this->field($text, 'alamat\s+jemput') ?: $loosePickup ?: $profileAddress;
        $destinationAddress = $this->field($text, 'alamat\s+antar') ?: $looseDestination;
        $passengers = $this->field($text, 'jumlah\s+penumpang') ?: $this->passengers($text) ?: '1';
        $notes = $this->field($text, 'catatan');

        if (! $destinationAddress) {
            return null;
        }

        return [
            'service_type' => $this->detectService($text) === 'joker_mobil' ? 'joker_mobil' : 'ojek',
            'customer_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'address' => $pickupAddress,
            'items' => [],
            'store_location' => $destinationAddress,
            'passengers' => max(1, (int) preg_replace('/\D/u', '', $passengers)),
            'stops' => [],
            'branch' => $branch,
            'payload' => [
                'service_type' => $this->detectService($text) === 'joker_mobil' ? 'joker_mobil' : 'ojek',
                'pickup_address' => $pickupAddress,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $destinationAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $destinationAddress,
                'notes' => trim(implode("\n", array_filter([
                    'Jumlah penumpang: '.$passengers,
                    $notes ? 'Catatan: '.$notes : null,
                    $rawText ?: $text,
                ]))),
                'service_payload' => [
                    'passengers' => max(1, (int) preg_replace('/\D/u', '', $passengers)),
                    'source' => 'smart_parser',
                    'normalized_text' => $text,
                ],
                'items' => [],
                'points' => [],
            ],
        ];
    }

    private function parseGiftOrder(User $user, string $text, ?Branch $branch, string $profileAddress, float $pickupLat, float $pickupLng, ?string $rawText = null): ?array
    {
        $receiverBlock = $this->afterMarker($text, 'Diantar ke');
        $receiverName = $this->field($receiverBlock, 'nama');
        $receiverPhone = $this->field($receiverBlock, '(?:hp|whatsapp|wa)(?:\s*\/\s*(?:hp|whatsapp|wa))*');
        $receiverAddress = $this->field($receiverBlock, 'alamat');
        $items = $this->items->extract($text);
        $purchaseAddress = $this->field($text, 'alamat\s+pembelian');
        $area = $this->field($text, 'area');
        $storeLocation = trim(implode(' - ', array_filter([$purchaseAddress, $area])));

        if (! $receiverName || ! $receiverPhone || ! $receiverAddress || $items === [] || $storeLocation === '') {
            return null;
        }

        return [
            'service_type' => 'gift_order',
            'customer_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'address' => $profileAddress,
            'items' => $items,
            'store_location' => $storeLocation,
            'receiver' => [
                'name' => $receiverName,
                'phone' => $receiverPhone,
                'address' => $receiverAddress,
            ],
            'stops' => [],
            'branch' => $branch,
            'payload' => [
                'service_type' => 'gift_order',
                'pickup_address' => $profileAddress,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $receiverAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $receiverAddress,
                'notes' => $rawText ?: $text,
                'service_payload' => [
                    'receiver' => compact('receiverName', 'receiverPhone', 'receiverAddress'),
                    'store_location' => $storeLocation,
                    'purchase_address' => $purchaseAddress,
                    'area' => $area,
                    'source' => 'smart_parser',
                    'normalized_text' => $text,
                ],
                'items' => $items,
                'points' => [],
            ],
        ];
    }

    private function field(string $text, string $label): ?string
    {
        if (preg_match('/(?:^|\R)\s*(?:'.$label.')\s*:\s*(.+)/iu', $text, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    private function routeAddresses(string $text): array
    {
        $patterns = [
            '/(?:alamat\s+jemput|jemput\s+di|jemput|dari)\s+(.+?)\s+(?:alamat\s+antar|antar\s+ke|tujuan|ke)\s+(.+)$/iu',
            '/(?:ojek|motor|mobil|joker\s+mobil).+?(?:dari|jemput\s+di)\s+(.+?)\s+(?:ke|tujuan|antar\s+ke)\s+(.+)$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return [$this->cleanAddress($match[1]), $this->cleanAddress($match[2])];
            }
        }

        if (preg_match('/(?:alamat\s+antar|tujuan|antar\s+ke|ke)\s+(.+)$/iu', $text, $match) === 1) {
            return [null, $this->cleanAddress($match[1])];
        }

        return [null, null];
    }

    private function passengers(string $text): ?string
    {
        if (preg_match('/(?:jumlah\s+)?penumpang\s*:?\s*(\d+)/iu', $text, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/(\d+)\s*(?:orang|penumpang)/iu', $text, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function cleanAddress(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+(?:jumlah\s+)?penumpang\s*:?\s*\d+.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+\d+\s*(?:orang|penumpang).*$/iu', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B.,");
    }

    private function afterMarker(string $text, string $marker): string
    {
        $position = mb_stripos($text, $marker);
        return $position === false ? $text : mb_substr($text, $position + mb_strlen($marker));
    }

    private function storeLocation(string $text): ?string
    {
        if (preg_match('/area\s*:\s*(.+)/iu', $text, $match) === 1) {
            return trim($match[1]);
        }

        if (preg_match('/alamat\s+pembelian\s*:\s*(.+?)(?:\R\s*\R|$)/isu', $text, $match) === 1) {
            return trim($match[1]);
        }

        if (preg_match_all('/(?:^|\R)\s*alamat\s*:\s*(.+)$/imu', $text, $matches) > 0) {
            $addresses = collect($matches[1])
                ->map(fn (string $value): string => trim($value))
                ->filter()
                ->values();

            $store = $addresses->reverse()->first(fn (string $value): bool => preg_match('/\b(?:warung|toko|resto|restaurant|rumah\s*makan|rm|depot|cafe|kafe|kedai|pasar|swalayan|mart|padang)\b/iu', $value) === 1);
            if ($store) {
                return $store;
            }

            if ($addresses->count() > 1) {
                return $addresses->last();
            }
        }

        return null;
    }

    private function profileAddress(User $user, ?Branch $branch): string
    {
        return (string) ($user->address ?? $user->alamat ?? $branch?->name ?? 'Alamat profile belum diisi');
    }

    private function branch(User $user): ?Branch
    {
        if ($user->branch_id) {
            return Branch::query()->find($user->branch_id);
        }

        return Branch::query()->whereNotNull('latitude')->whereNotNull('longitude')->first();
    }
}
