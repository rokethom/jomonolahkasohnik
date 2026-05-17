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

        return $this->parseFast($user, $text, false);
    }

    public function parseFastFirst(User $user, string $text): ?array
    {
        $fastParsed = $this->parseFast($user, $text, false);
        if ($fastParsed !== null) {
            return $fastParsed;
        }

        return $this->aiParser->parse($user, $text);
    }

    public function parseFast(User $user, string $text, bool $allowAiFallback = true): ?array
    {
        $normalizedText = $this->normalizer->normalize($text);
        $natural = $this->naturalLanguage->parse($user, $text);
        if ($natural !== null) {
            $natural['payload']['service_payload']['raw_text'] = $text;
            $natural['payload']['service_payload']['normalized_text'] = $normalizedText;
            return $natural;
        }

        $localParsed = $this->parseLocal($user, $text, $normalizedText);
        if ($localParsed !== null) {
            return $localParsed;
        }

        return $allowAiFallback ? $this->aiParser->parse($user, $text) : null;
    }

    private function parseLocal(User $user, string $text, string $normalizedText): ?array
    {
        $items = $this->items->extract($text) ?: $this->items->extract($normalizedText);
        $storeLocation = $this->storeLocation($text) ?: $this->storeLocation($normalizedText);
        $serviceType = $this->detectService($normalizedText);
        if (! $serviceType && $items !== [] && $storeLocation) {
            $serviceType = 'DO';
        }

        if (! $serviceType) {
            return null;
        }

        $branch = $this->branch($user, $normalizedText);
        $profileAddress = $this->profileAddress($user, $branch);
        $pickupLat = (float) ($branch?->latitude ?: -6.9219);
        $pickupLng = (float) ($branch?->longitude ?: 107.6071);

        if ($serviceType === 'kurir') {
            return $this->parseCourier($user, $text, $branch, $profileAddress, $pickupLat, $pickupLng, $text);
        }

        if (in_array($serviceType, ['ojek', 'joker_mobil'], true)) {
            return $this->parseOjek($user, $text, $branch, $profileAddress, $pickupLat, $pickupLng, $text);
        }

        if ($serviceType === 'gift_order') {
            return $this->parseGiftOrder($user, $text, $branch, $profileAddress, $pickupLat, $pickupLng, $text);
        }

        if ($items === [] || ! $storeLocation) {
            if (in_array($serviceType, ['DO', 'belanja'], true)) {
                $freeTextPurchase = $this->parseFreeTextPurchase($user, $text, $normalizedText, $branch, $profileAddress, $pickupLat, $pickupLng);
                if ($freeTextPurchase !== null) {
                    return $freeTextPurchase;
                }
            }

            return null;
        }

        $destinationAddress = $this->destinationAddress($text, $profileAddress)
            ?: $this->destinationAddress($normalizedText, $profileAddress)
            ?: $profileAddress;
        if (! $destinationAddress) {
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
                'destination_address' => $destinationAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $destinationAddress,
                'notes' => $text,
                'service_payload' => [
                    'store_location' => $storeLocation,
                    'location_flow_note' => 'Alamat pembelian dipakai sebagai titik ambil barang; alamat antar wajib mengikuti input customer. Alamat profile hanya untuk validasi pendaftaran.',
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

        if (preg_match('/(?:antar|jemput|dari)\s+.+\s+(?:ke|tujuan|antar\s+ke)\s+.+/iu', $text) === 1) {
            return 'ojek';
        }

        if (preg_match('/gift\s+order|pesanan\s+gift|(?:^|\W)(?:gift|kado|hadiah)(?:\W|$)/iu', $text) === 1) {
            return 'gift_order';
        }

        return preg_match('/delivery\s+order|(?:^|\W)do(?:\W|$)|(?:^|\W)(?:beli|belikan|pesan|pesanan)(?:\W|$)/iu', $text) === 1 ? 'DO' : null;
    }

    private function parseCourier(User $user, string $text, ?Branch $branch, string $profileAddress, float $pickupLat, float $pickupLng, ?string $rawText = null): ?array
    {
        $receiverBlock = $this->afterMarker($text, 'Antarkan barang ke');
        $receiverName = $this->field($receiverBlock, 'nama');
        $receiverPhone = $this->field($receiverBlock, '(?:hp|telepon|whatsapp|wa)(?:\s*\/\s*(?:hp|telepon|whatsapp|wa))*');
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
        $pickupAddress = $this->normalizeProfileAddress(
            $this->field($text, 'alamat\s+jemput') ?: $loosePickup ?: $profileAddress,
            $profileAddress,
        );
        $destinationAddress = $this->field($text, 'alamat\s+antar') ?: $looseDestination;
        $passengers = $this->field($text, 'jumlah\s+penumpang') ?: $this->passengers($text) ?: '1';
        $seatRows = $this->vehicleSeatRows($text);
        $notes = $this->field($text, 'catatan');
        $serviceType = $this->detectService($text) === 'joker_mobil' ? 'joker_mobil' : 'ojek';

        if (! $destinationAddress) {
            return null;
        }

        $payload = [
            'service_type' => $serviceType,
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
                $serviceType === 'joker_mobil' ? 'Seat / baris mobil: '.$seatRows.' baris' : null,
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
        ];

        if ($serviceType === 'joker_mobil') {
            $payload['preferred_vehicle_type'] = 'mobil';
            $payload['vehicle_seat_rows'] = $seatRows;
            $payload['service_payload']['preferred_vehicle_type'] = 'mobil';
            $payload['service_payload']['vehicle_seat_rows'] = $seatRows;
        }

        return [
            'service_type' => $serviceType,
            'customer_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'address' => $pickupAddress,
            'items' => [],
            'store_location' => null,
            'destination' => $destinationAddress,
            'passengers' => max(1, (int) preg_replace('/\D/u', '', $passengers)),
            'stops' => [],
            'branch' => $branch,
            'payload' => $payload,
        ];
    }

    private function parseGiftOrder(User $user, string $text, ?Branch $branch, string $profileAddress, float $pickupLat, float $pickupLng, ?string $rawText = null): ?array
    {
        $receiverBlock = $this->afterMarker($text, 'Diantar ke');
        $receiverName = $this->field($receiverBlock, 'nama');
        $receiverPhone = $this->field($receiverBlock, '(?:hp|telepon|whatsapp|wa)(?:\s*\/\s*(?:hp|telepon|whatsapp|wa))*');
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

    private function parseFreeTextPurchase(User $user, string $text, string $normalizedText, ?Branch $branch, string $profileAddress, float $pickupLat, float $pickupLng): ?array
    {
        $contact = $this->freeTextContact($text, $profileAddress);
        $items = $this->freeTextPurchaseItems($text, $normalizedText);
        $storeLocation = $this->freeTextStoreLocation($text, $normalizedText);
        $destinationAddress = $this->destinationAddress($text, $profileAddress)
            ?: $this->destinationAddress($normalizedText, $profileAddress)
            ?: $contact['address']
            ?: $profileAddress;

        if ($items === [] || ! $storeLocation || ! $destinationAddress) {
            return null;
        }

        return [
            'service_type' => 'DO',
            'customer_id' => $user->id,
            'name' => $contact['name'] ?: $user->name,
            'phone' => $contact['phone'] ?: $user->phone,
            'address' => $destinationAddress,
            'items' => $items,
            'store_location' => $storeLocation,
            'stops' => [],
            'branch' => $branch,
            'customer' => [
                'name' => $contact['name'] ?: $user->name,
                'phone' => $contact['phone'] ?: $user->phone,
                'address' => $destinationAddress,
            ],
            'destination' => $destinationAddress,
            'payload' => [
                'service_type' => 'DO',
                'pickup_address' => $storeLocation,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'destination_address' => $destinationAddress,
                'destination_lat' => $pickupLat + 0.018,
                'destination_lng' => $pickupLng + 0.018,
                'branch_id' => $branch?->id,
                'stops' => 1,
                'destination_text' => $destinationAddress,
                'notes' => $text,
                'service_payload' => [
                    'store_location' => $storeLocation,
                    'purchase_address' => $storeLocation,
                    'customer' => $contact,
                    'source' => 'free_text_purchase_parser',
                    'normalized_text' => $normalizedText,
                ],
                'items' => $items,
                'points' => [],
            ],
        ];
    }

    /**
     * @return array{name: ?string, phone: ?string, address: ?string}
     */
    private function freeTextContact(string $text, string $profileAddress): array
    {
        $name = $this->field($text, 'pesan\s+atas\s+nama|atas\s+nama|nama');
        $phone = $this->field($text, '(?:no\s*)?(?:hp|telepon|whatsapp|wa)(?:\s*\/\s*(?:hp|telepon|whatsapp|wa))*');
        $address = $this->field($text, 'alamat\s+antar|alamat\s+tujuan|alamat');

        $lines = collect(preg_split('/\R/u', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();

        if (! $phone) {
            $phone = $lines->first(fn (string $line): bool => preg_match('/(?:\+?62|0)8\d{7,13}/u', $line) === 1);
        }

        if (! $address && $phone) {
            $phoneIndex = $lines->search($phone);
            if (is_int($phoneIndex) && $phoneIndex > 0) {
                $address = $lines->get($phoneIndex - 1);
            }
        }

        if (! $name && $phone) {
            $phoneIndex = $lines->search($phone);
            if (is_int($phoneIndex) && $phoneIndex > 1) {
                $candidate = $lines->get($phoneIndex - 2);
                if (is_string($candidate) && preg_match('/\b(?:beli|belikan|pesan|alamat|no|hp|wa|telepon)\b/iu', $candidate) !== 1) {
                    $name = $candidate;
                }
            }
        }

        $address = $address ? $this->normalizeDestination($address, $profileAddress) : null;

        return [
            'name' => $name ? $this->cleanAddress($name) : null,
            'phone' => $phone ? preg_replace('/[^\d+]/u', '', $phone) : null,
            'address' => $address,
        ];
    }

    private function freeTextStoreLocation(string $text, string $normalizedText): ?string
    {
        foreach (array_filter([
            $this->field($text, 'pesan|pesanan|belikan|pembelian|order'),
            $this->field($normalizedText, 'pesan|pesanan|belikan|pembelian|order'),
        ]) as $orderBlock) {
            if (preg_match('/\b(?:d|di|dari|sebelah|seblh|sblh|samping|depan)\s+(.+?)(?=\s*,|\R|$)/iu', $orderBlock, $match) === 1) {
                return $this->cleanAddress($match[1]);
            }
        }

        foreach ([$text, $normalizedText] as $candidate) {
            $storeLocation = $this->storeLocation($candidate);
            if ($storeLocation) {
                return $storeLocation;
            }

            if (preg_match('/\b(?:beli|belikan|pesan)\s*:?\s*(?:\s+.+?)?\s+(?:d|di|dari|sebelah|seblh|sblh|samping|depan)\s+(.+?)(?=(?:[,.;]|\R|$))/iu', $candidate, $match) === 1) {
                return $this->cleanAddress($match[1]);
            }
        }

        return null;
    }

    private function freeTextPurchaseItems(string $text, string $normalizedText): array
    {
        $labelBlock = $this->field($text, 'pesan|pesanan|belikan|pembelian|order');
        if ($labelBlock) {
            return $this->parsePurchaseItemList($labelBlock);
        }

        foreach ([$text, $normalizedText] as $candidate) {
            if (preg_match('/\b(?:beli|belikan|pesan)\s+(.+?)(?=\s+(?:beli\s+)?(?:di|dari|sebelah|samping|depan)\b|[,.;]|\R|$)/iu', $candidate, $match) === 1) {
                $items = $this->parsePurchaseItemList($match[1]);
                if ($items !== []) {
                    return $items;
                }
            }
        }

        return [];
    }

    /**
     * @return array<int, array{name: string, quantity: int}>
     */
    private function parsePurchaseItemList(string $value): array
    {
        $parts = preg_split('/\s*,\s*|\R+/u', trim($value)) ?: [];

        return collect($parts)
            ->map(fn (string $item): string => preg_replace('/\s+(?:d|di|dari|sebelah|seblh|sblh|samping|depan)\s+.+$/iu', '', trim($item)) ?? trim($item))
            ->map(fn (string $item): string => trim($item, " \t\n\r\0\x0B.,;"))
            ->filter()
            ->map(function (string $item): array {
                $quantity = 1;

                if (preg_match('/^(\d+)\s*(?:x|pcs?|porsi|bungkus|buah|gelas|botol|pack|kotak)?\s+(.+)$/iu', $item, $match) === 1) {
                    $quantity = max(1, (int) $match[1]);
                    $item = trim($match[2]);
                } elseif (preg_match('/\s+(\d+)\s+area\s+.+$/iu', $item, $match) === 1) {
                    $quantity = max(1, (int) $match[1]);
                    $item = trim(substr($item, 0, -strlen($match[0])));
                } elseif (preg_match('/\s+(\d+)\s*(?:x|pcs?|porsi|bungkus|buah|gelas|botol|pack|kotak)?\s*$/iu', $item, $match) === 1) {
                    $quantity = max(1, (int) $match[1]);
                    $item = trim(substr($item, 0, -strlen($match[0])));
                }

                $item = preg_replace('/^(?:[-*]\s*|[a-z0-9]+\s*[:.)-]\s*)/iu', '', $item) ?? $item;
                $item = preg_replace('/\s+/u', ' ', trim($item)) ?? trim($item);
                $lower = mb_strtolower($item);

                return [
                    'name' => mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1),
                    'quantity' => $quantity,
                ];
            })
            ->filter(fn (array $item): bool => $item['name'] !== '')
            ->values()
            ->all();
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

    private function vehicleSeatRows(string $text): int
    {
        if (preg_match('/(?:seat|kursi|baris|tempat\s+duduk)(?:\s*\/\s*baris)?\s*(?:mobil)?\s*:?\s*(\d+)/iu', $text, $match) === 1) {
            return (int) $match[1] === 3 ? 3 : 2;
        }

        if (preg_match('/\b([23])\s*baris\b/iu', $text, $match) === 1) {
            return (int) $match[1] === 3 ? 3 : 2;
        }

        return 2;
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

    private function destinationAddress(string $text, string $profileAddress): ?string
    {
        if (preg_match('/(?:alamat\s+antar|alamat\s+tujuan|tujuan|antar\s+ke|kirim\s+ke|ke)\s*:\s*(.+)$/imu', $text, $match) === 1) {
            return $this->normalizeDestination($match[1], $profileAddress);
        }

        if (preg_match('/\b(?:alamat\s+antar|alamat\s+tujuan|tujuan|antar\s+ke|kirim\s+ke)\s+(.+)$/iu', $text, $match) === 1) {
            return $this->normalizeDestination($match[1], $profileAddress);
        }

        return null;
    }

    private function normalizeDestination(string $value, string $profileAddress): string
    {
        $destination = $this->cleanAddress($value);
        $normalized = mb_strtolower($destination);

        return $this->isProfileAddressReference($normalized)
            ? $profileAddress
            : $destination;
    }

    private function normalizeProfileAddress(string $value, string $profileAddress): string
    {
        $address = $this->cleanAddress($value);
        $normalized = mb_strtolower($address);

        return $this->isProfileAddressReference($normalized)
            ? $profileAddress
            : $address;
    }

    private function isProfileAddressReference(string $normalized): bool
    {
        $normalized = str($normalized)
            ->lower()
            ->replaceMatches('/\b(?:saya|aku|ku|di|ke|jemput|alamat|lokasi|titik)\b/u', ' ')
            ->squish()
            ->toString();

        return in_array($normalized, ['rumah', 'profile', 'home'], true);
    }

    private function profileAddress(User $user, ?Branch $branch): string
    {
        return (string) ($user->address ?? $user->alamat ?? $branch?->name ?? 'Alamat profile belum diisi');
    }

    private function branch(User $user, ?string $text = null): ?Branch
    {
        $branchFromText = $this->branchFromText($text);
        if ($branchFromText) {
            return $branchFromText;
        }

        if ($user->branch_id) {
            $branchId = Branch::resolveOperationalAreaId((int) $user->branch_id, (float) $user->lat ?: null, (float) $user->lng ?: null)
                ?? (int) $user->branch_id;

            return Branch::query()->find($branchId);
        }

        return Branch::query()->operationalAreas()->whereNotNull('latitude')->whereNotNull('longitude')->first();
    }

    private function branchFromText(?string $text): ?Branch
    {
        if (! $text) {
            return null;
        }

        $hint = $this->field($text, 'branch\s*id|id\s*cabang|kode\s*area');
        if ($hint && is_numeric($hint)) {
            $branchId = Branch::resolveOperationalAreaId((int) $hint)
                ?? (int) $hint;

            return Branch::query()->find($branchId);
        }

        $hint ??= $this->field($text, 'area|cabang|branch');
        $needle = str($hint ?? '')
            ->lower()
            ->replace(['-', '_', '/', ','], ' ')
            ->squish()
            ->toString();

        if ($needle === '') {
            return null;
        }

        $matches = Branch::query()
            ->operationalAreas()
            ->get(['id', 'branch_code', 'name', 'area', 'latitude', 'longitude'])
            ->flatMap(function (Branch $branch) use ($needle): array {
                $normalizedCode = str((string) $branch->branch_code)
                    ->lower()
                    ->replace(['-', '_', '/', ','], ' ')
                    ->squish()
                    ->toString();

                return collect([
                    $branch->branch_code,
                    trim(($branch->branch_code ?? '').' '.($branch->name ?? '').' '.($branch->area ?? '')),
                    trim(($branch->name ?? '').' '.($branch->area ?? '')),
                    trim(($branch->area ?? '').' '.($branch->name ?? '')),
                    $branch->area,
                    $branch->name,
                ])
                    ->map(fn (mixed $candidate): string => str((string) $candidate)
                        ->lower()
                        ->replace(['-', '_', '/', ','], ' ')
                        ->squish()
                        ->toString())
                    ->filter()
                    ->unique()
                    ->filter(fn (string $candidate): bool => $candidate === $needle || str_contains($needle, $candidate))
                    ->map(fn (string $candidate): array => [
                        'branch_id' => $branch->id,
                        'score' => ($candidate === $needle ? 10_000 : 0) + ($normalizedCode !== '' && str_starts_with($candidate, $normalizedCode) ? 1_000 : 0) + strlen($candidate),
                    ])
                    ->all();
            })
            ->sortByDesc('score')
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }

        $topScore = (int) $matches->first()['score'];
        $topBranchIds = $matches
            ->filter(fn (array $match): bool => (int) $match['score'] === $topScore)
            ->pluck('branch_id')
            ->unique()
            ->values();

        if ($topBranchIds->count() > 1) {
            return null;
        }

        $branchId = Branch::resolveOperationalAreaId((int) $topBranchIds->first())
            ?? (int) $topBranchIds->first();

        return Branch::query()->find($branchId);
    }
}
