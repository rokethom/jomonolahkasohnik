<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use App\Services\Spatial\GeojsonRegionLookupService;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class JojoBotService
{
    private const MENU_KEYWORDS = ['order', 'menu', 'min', 'hai', 'admin', 'jojo'];
    private const SHOPPING_KEYWORDS = ['belikan', 'pasar'];
    private const PRICING_GEOCODE_CANDIDATES = 3;

    /** @var array<string, array|null> */
    private array $pricingGeocodeMemo = [];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly OrderParserService $orderParser,
        private readonly GeocodingService $geocoding,
        private readonly TextFormatter $formatter,
        private readonly KeywordParserService $keywordParsers,
        private readonly GeojsonRegionLookupService $geojsonRegions,
        private readonly LocationPoiService $locationPois,
        private readonly AiAliasMapService $aliasMaps,
        private readonly SettingService $settings,
    ) {
    }

    public function preview(User $user, string $rawText): array
    {
        $services = $this->services();
        $normalized = mb_strtolower(trim($rawText));
        $parsed = $this->parseOrderText($rawText);
        if ($this->isStructuredFormInput($rawText) && ($parsed['service_type'] || $parsed['pickup_address'] || $parsed['destination_address'] || $parsed['store_location'])) {
            $serviceType = $parsed['service_type'] ?: $this->detectService($normalized, $services) ?: ($this->containsAny($normalized, self::SHOPPING_KEYWORDS) ? 'belanja' : 'delivery');
            $parsed = $this->completeParsedForPreview($user, $parsed, $serviceType);

            if ($this->isPurchaseService($serviceType) && blank($parsed['store_location'] ?? null)) {
                return $this->missingPurchaseLocationResponse($services, $serviceType, $parsed);
            }

            $payload = $this->payload($user, $parsed, $serviceType, $this->shouldUseBaseFare($parsed));
            $quote = $this->pricing->calculate($payload);

            return [
                'intent' => 'order_preview',
                'services' => $services->values(),
                'selected_service' => $serviceType,
                'parsed' => $parsed,
                'message' => $this->orderPreviewReply($parsed, $quote),
                'form_schema' => null,
                'service_type' => $serviceType,
                'quote' => $quote,
                'order_payload' => $payload,
                'actions' => ['add_point', 'preview_order'],
                'reply' => $this->orderPreviewReply($parsed, $quote),
            ];
        }

        $keywordMatch = $this->keywordParsers->detect($rawText);

        if ($keywordMatch !== null) {
            $serviceType = $parsed['service_type'] ?: $this->serviceType($keywordMatch['service_type'], $keywordMatch['service_type']);
            $smartParsed = $this->shouldTrySmartParserForKeyword($rawText, $keywordMatch)
                ? $this->orderParser->parseFastFirst($user, $rawText)
                : null;

            if ($smartParsed) {
                if ($parsed['service_type']) {
                    $smartParsed['service_type'] = $parsed['service_type'];
                    $smartParsed['payload']['service_type'] = $parsed['service_type'];
                }

                $payload = $this->hydratePayloadCoordinates($smartParsed['payload'], $smartParsed['branch'] ?? $this->branch($user), $user);
                $quote = $this->pricing->calculate($payload);
                $reply = $this->formatter->smartParserReply($smartParsed, $quote);

                return [
                    'intent' => 'order_preview',
                    'services' => $services->values(),
                    'selected_service' => $payload['service_type'],
                    'parsed' => [
                        'name' => $smartParsed['name'],
                        'phone' => $smartParsed['phone'],
                        'pickup_address' => $payload['pickup_address'],
                        'destination_address' => $payload['destination_address'],
                        'notes' => $rawText,
                        'items' => $smartParsed['items'],
                        'store_location' => $smartParsed['store_location'],
                        'destination' => $smartParsed['destination'] ?? $payload['destination_address'],
                        'customer' => $smartParsed['customer'] ?? [
                            'name' => $smartParsed['name'],
                            'phone' => $smartParsed['phone'],
                        ],
                        'smart_parser' => true,
                        'keyword_parser' => $keywordMatch,
                    ],
                    'message' => $reply,
                    'form_schema' => null,
                    'service_type' => $payload['service_type'],
                    'quote' => $quote,
                    'order_payload' => $payload,
                    'actions' => ['add_point', 'preview_order'],
                    'reply' => $reply,
                ];
            }

            $isStructuredFormInput = $this->isStructuredFormInput($rawText);
            if (($parsed['pickup_address'] ?? null) && ($parsed['destination_address'] ?? null) || $isStructuredFormInput) {
                if ($isStructuredFormInput) {
                    $parsed = $this->completeParsedForPreview($user, $parsed, $serviceType);
                }

                if ($this->isPurchaseService($serviceType) && blank($parsed['store_location'] ?? null)) {
                    return $this->missingPurchaseLocationResponse($services, $serviceType, $parsed, $keywordMatch['form_schema'] ?? null);
                }
                if (blank($parsed['destination_address'] ?? null)) {
                    return $this->missingDestinationResponse($services, $serviceType, $parsed, $keywordMatch['form_schema'] ?? null);
                }

                $parsed = $this->completeParsedForPreview($user, $parsed, $serviceType);
                $payload = $this->payload($user, $parsed, $serviceType, $this->shouldUseBaseFare($parsed));
                $quote = $this->pricing->calculate($payload);

                return [
                    'intent' => 'order_preview',
                    'services' => $services->values(),
                    'selected_service' => $serviceType,
                    'parsed' => [
                        ...$parsed,
                        'keyword_parser' => $keywordMatch,
                        'parser_mode' => $keywordMatch['parser_mode'],
                    ],
                    'message' => $this->orderPreviewReply($parsed, $quote),
                    'form_schema' => null,
                    'service_type' => $serviceType,
                    'quote' => $quote,
                    'order_payload' => $payload,
                    'actions' => ['add_point', 'preview_order'],
                    'reply' => $this->orderPreviewReply($parsed, $quote),
                ];
            }

            return [
                'intent' => 'service_selected',
                'services' => $services->values(),
                'selected_service' => $serviceType,
                'parsed' => [
                    'keyword_parser' => $keywordMatch,
                    'parser_mode' => $keywordMatch['parser_mode'],
                ],
                'message' => $keywordMatch['response'],
                'form_schema' => $keywordMatch['form_schema'] ?? null,
                'service_type' => $serviceType,
                'quote' => null,
                'order_payload' => null,
                'reply' => $keywordMatch['response'],
            ];
        }

        $selectedService = $this->detectService($normalized, $services);
        if ($parsed['service_type']) {
            $selectedService = $parsed['service_type'];
        }

        if ($this->containsAny($normalized, self::MENU_KEYWORDS)) {
            return [
                'intent' => 'service_menu',
                'services' => $services->values(),
                'selected_service' => null,
                'parsed' => $parsed,
                'quote' => null,
                'order_payload' => null,
                'reply' => $this->serviceMenuReply($services),
            ];
        }

        if ($selectedService && ! $this->looksLikeFreeTextOrder($normalized)) {
            return [
                'intent' => 'service_selected',
                'services' => $services->values(),
                'selected_service' => $selectedService,
                'parsed' => $parsed,
                'quote' => null,
                'order_payload' => null,
                'form_schema' => $this->defaultFormSchema($selectedService),
                'service_type' => $selectedService,
                'reply' => "Baik, JOJOBOT arahkan ke layanan ".mb_strtoupper($selectedService).".\n\nSilakan lengkapi form order di bawah.",
            ];
        }

        $smartParsed = $this->isStructuredFormInput($rawText) ? null : $this->orderParser->parseFastFirst($user, $rawText);

        if ($smartParsed) {
            $payload = $this->hydratePayloadCoordinates($smartParsed['payload'], $smartParsed['branch'] ?? $this->branch($user), $user);
            $quote = $this->pricing->calculate($payload);

            return [
                'intent' => 'order_preview',
                'services' => $services->values(),
                'selected_service' => $payload['service_type'],
                'parsed' => [
                    'name' => $smartParsed['name'],
                    'phone' => $smartParsed['phone'],
                    'pickup_address' => $payload['pickup_address'],
                    'destination_address' => $payload['destination_address'],
                    'notes' => $rawText,
                    'items' => $smartParsed['items'],
                    'store_location' => $smartParsed['store_location'],
                    'destination' => $smartParsed['destination'] ?? $payload['destination_address'],
                    'customer' => $smartParsed['customer'] ?? [
                        'name' => $smartParsed['name'],
                        'phone' => $smartParsed['phone'],
                    ],
                    'smart_parser' => true,
                ],
                'quote' => $quote,
                'order_payload' => $payload,
                'actions' => ['add_point', 'preview_order'],
                'reply' => $this->formatter->smartParserReply($smartParsed, $quote),
            ];
        }

        if ($this->containsAny($normalized, self::SHOPPING_KEYWORDS)) {
            $selectedService = 'belanja';
        }

        $hasOrderShape = $parsed['pickup_address'] && $parsed['destination_address'];

        if ($selectedService && $this->isOutsideRegisteredArea($user) && ! $this->isOutsideAreaServiceType($selectedService, $services)) {
            return $this->giftOrderDirection($services, $parsed);
        }

        if ($hasOrderShape || (($selectedService || ($parsed['service_type'] ?? null)) && $this->isStructuredFormInput($rawText))) {
            $serviceType = $parsed['service_type'] ?: $selectedService ?: 'delivery';
            if ($this->isPurchaseService($serviceType) && blank($parsed['store_location'] ?? null)) {
                return $this->missingPurchaseLocationResponse($services, $serviceType, $parsed);
            }
            if (blank($parsed['destination_address'] ?? null)) {
                return $this->missingDestinationResponse($services, $serviceType, $parsed);
            }

            $parsed = $this->completeParsedForPreview($user, $parsed, $serviceType);
            $payload = $this->payload($user, $parsed, $serviceType, $this->shouldUseBaseFare($parsed));
            $quote = $this->pricing->calculate($payload);

            return [
                'intent' => 'order_preview',
                'services' => $services->values(),
                'selected_service' => $payload['service_type'],
                'parsed' => $parsed,
                'quote' => $quote,
                'order_payload' => $payload,
                'actions' => ['add_point', 'preview_order'],
                'reply' => $this->orderPreviewReply($parsed, $quote),
            ];
        }

        if ($selectedService) {
            return [
                'intent' => 'service_selected',
                'services' => $services->values(),
                'selected_service' => $selectedService,
                'parsed' => $parsed,
                'quote' => null,
                'order_payload' => null,
                'form_schema' => $this->defaultFormSchema($selectedService),
                'service_type' => $selectedService,
                'reply' => "Baik, JOJOBOT arahkan ke layanan ".mb_strtoupper($selectedService).".\n\nSilakan lengkapi form order di bawah.",
            ];
        }

        if ($this->containsAny($normalized, self::MENU_KEYWORDS)) {
            return [
                'intent' => 'service_menu',
                'services' => $services->values(),
                'selected_service' => null,
                'parsed' => $parsed,
                'quote' => null,
                'order_payload' => null,
                'reply' => $this->serviceMenuReply($services),
            ];
        }

        return [
            'intent' => 'fallback_form',
            'services' => $services->values(),
            'selected_service' => null,
            'parsed' => $parsed,
            'quote' => null,
            'order_payload' => null,
            'reply' => "JOJOBOT belum bisa membaca format pesanan.\nSilakan isi form manual layanan.",
            'fallback_format' => $this->formatter->unrecognizedFormat(),
        ];
    }

    private function services(): Collection
    {
        $rows = Service::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'whatsapp_redirect_enabled', 'outside_area_only', 'whatsapp_number', 'whatsapp_message_template']);

        if ($rows->isEmpty()) {
            $rows = collect([
                ['id' => 1, 'code' => 'DO', 'name' => 'Delivery'],
                ['id' => 2, 'code' => 'OJ', 'name' => 'Ojek'],
                ['id' => 3, 'code' => 'KR', 'name' => 'Kurir'],
                ['id' => 4, 'code' => 'BL', 'name' => 'Belanja'],
                ['id' => 5, 'code' => 'GO', 'name' => 'Gift Order'],
            ]);
        }

        return $rows->map(fn ($service): array => [
            'id' => (int) ($service['id'] ?? $service->id),
            'code' => (string) ($service['code'] ?? $service->code),
            'name' => (string) ($service['name'] ?? $service->name),
            'service_type' => $this->serviceType((string) ($service['code'] ?? $service->code), (string) ($service['name'] ?? $service->name)),
            'whatsapp_redirect_enabled' => (bool) ($service['whatsapp_redirect_enabled'] ?? $service->whatsapp_redirect_enabled ?? false),
            'outside_area_only' => (bool) ($service['outside_area_only'] ?? $service->outside_area_only ?? $this->isGiftOrder($this->serviceType((string) ($service['code'] ?? $service->code), (string) ($service['name'] ?? $service->name)))),
            'whatsapp_number' => $service['whatsapp_number'] ?? $service->whatsapp_number ?? null,
            'whatsapp_message_template' => $service['whatsapp_message_template'] ?? $service->whatsapp_message_template ?? null,
        ]);
    }

    private function detectService(string $text, Collection $services): ?string
    {
        foreach ($services as $service) {
            if (str_contains($text, mb_strtolower($service['name'])) || str_contains($text, mb_strtolower($service['service_type']))) {
                return $service['service_type'];
            }
        }

        return null;
    }

    private function parseOrderText(string $rawText): array
    {
        $fields = [
            'name' => null,
            'phone' => null,
            'pickup_address' => null,
            'destination_address' => null,
            'store_location' => null,
            'notes' => null,
            'points' => [],
            'used_fallback_location' => false,
            'service_type' => null,
            'driver_preference' => 'general',
            'branch_id' => null,
        ];
        $activeMultilineField = null;
        $activeSection = 'sender';

        foreach (preg_split('/\R/u', $rawText) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                if (preg_match('/antarkan\s+barang\s+ke|antar(?:kan)?\s+ke|diantar\s+ke|penerima|tujuan/u', mb_strtolower($line)) === 1) {
                    $activeSection = 'receiver';
                }

                if ($activeMultilineField === 'notes' && trim($line) !== '') {
                    $fields['notes'] = trim(implode("\n", array_filter([$fields['notes'], trim($line)])));
                }

                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $line, 2));
            $key = mb_strtolower($label);
            $activeMultilineField = null;

            if (preg_match('/layanan|service/u', $key)) {
                $fields['service_type'] = $this->normalizeRequestedService($value);
            } elseif (preg_match('/branch\s*id|id\s*cabang|kode\s*area/u', $key)) {
                $fields['branch_id'] = is_numeric($value) ? (int) $value : $this->branchIdFromText($value);
            } elseif (preg_match('/\barea\b|cabang|branch/u', $key)) {
                $fields['branch_id'] = $this->branchIdFromText($value) ?? $fields['branch_id'];
            } elseif (preg_match('/^nama/u', $key)) {
                if ($activeSection !== 'receiver' || blank($fields['name'])) {
                    $fields['name'] = $value;
                }
            } elseif (preg_match('/no|hp|wa|telepon|phone/u', $key)) {
                if ($activeSection !== 'receiver' || blank($fields['phone'])) {
                    $fields['phone'] = $value;
                }
            } elseif (preg_match('/alamat\s+pembelian|lokasi\s+(?:beli|pembelian|toko)|toko|store|warung|resto|restaurant|pasar/u', $key)) {
                $fields['store_location'] = $value;
            } elseif (preg_match('/jemput|pickup|asal/u', $key)) {
                $fields['pickup_address'] = $value;
            } elseif (preg_match('/tujuan|antar|destination/u', $key)) {
                $fields['destination_address'] = $value;
            } elseif (preg_match('/\balamat\b|address/u', $key)) {
                if ($activeSection === 'receiver') {
                    $fields['destination_address'] = $value;
                } elseif (blank($fields['pickup_address'])) {
                    $fields['pickup_address'] = $value;
                } else {
                    $fields['destination_address'] = $value;
                }
            } elseif (preg_match('/barang|item|produk|list|belanja|pembelian|belikan/u', $key)) {
                $fields['notes'] = trim(implode("\n", array_filter([$fields['notes'], $value])));
                $activeMultilineField = 'notes';
            } elseif (preg_match('/rute|route/u', $key)) {
                $fields['route'] = $value;
            } elseif (preg_match('/seat|kursi|baris|tempat\s+duduk/u', $key)) {
                $fields['vehicle_seat_rows'] = str_contains($value, '3') ? 3 : 2;
            } elseif (preg_match('/preferensi\s+driver|pilihan\s+driver|driver/u', $key)) {
                $fields['driver_preference'] = str_contains(mb_strtolower($value), 'ladies') ? 'ladies' : 'general';
            } elseif (preg_match('/catatan|notes|barang|pesanan/u', $key)) {
                $fields['notes'] = trim(implode("\n", array_filter([$fields['notes'], $value])));
                $activeMultilineField = 'notes';
            } elseif (preg_match('/titik|stop|mampir/u', $key)) {
                $fields['points'][] = ['address' => $value, 'label' => $label];
            }
        }

        if (str_contains($rawText, ',') && ! $fields['notes']) {
            $fields['notes'] = $rawText;
        }

        return $fields;
    }

    private function completeParsedForPreview(User $user, array $parsed, string $serviceType): array
    {
        if ($this->isPurchaseService($serviceType)) {
            if (blank($parsed['pickup_address'] ?? null)) {
                $parsed['pickup_address'] = $parsed['store_location'] ?: ($this->branch($user)?->name ?? 'Lokasi pembelian');
                $parsed['used_fallback_location'] = true;
            }

            if (blank($parsed['destination_address'] ?? null)) {
                $parsed['destination_address'] = $user->address ?: 'Alamat customer';
                $parsed['used_fallback_location'] = true;
            }

            return $parsed;
        }

        if (blank($parsed['destination_address'] ?? null)) {
            $parsed['destination_address'] = $user->address ?: 'Alamat customer';
            $parsed['used_fallback_location'] = true;
        }

        if (blank($parsed['pickup_address'] ?? null)) {
            $parsed['pickup_address'] = $this->branch($user)?->name ?? 'Lokasi jemput';
            $parsed['used_fallback_location'] = true;
        }

        return $parsed;
    }

    private function shouldUseBaseFare(array $parsed): bool
    {
        return (bool) ($parsed['used_fallback_location'] ?? false);
    }

    private function isStructuredFormInput(string $rawText): bool
    {
        return preg_match('/^[^:\r\n]{2,80}:/mu', $rawText) === 1;
    }

    private function shouldTrySmartParserForKeyword(string $rawText, array $keywordMatch): bool
    {
        if (($keywordMatch['parser_mode'] ?? null) === 'advanced') {
            return ! $this->isStructuredFormInput($rawText);
        }

        $normalized = mb_strtolower(trim($rawText));
        if ($this->isStructuredFormInput($rawText)) {
            return false;
        }

        $wordCount = str_word_count(str_replace(['/', '-'], ' ', $normalized));
        if ($wordCount < 3) {
            return false;
        }

        return preg_match('/\b(?:belikan|beli|pesan|antar|jemput|tujuan|alamat|lokasi|toko|warung|resto|pasar|ke|dari)\b/iu', $normalized) === 1;
    }

    private function looksLikeFreeTextOrder(string $normalized): bool
    {
        $wordCount = str_word_count(str_replace(['/', '-'], ' ', $normalized));
        if ($wordCount < 3) {
            return false;
        }

        return preg_match('/\b(?:belikan|beli|pesan|antar|antarkan|jemput|tujuan|alamat|lokasi|toko|warung|resto|pasar|kirim|ke|dari)\b/iu', $normalized) === 1;
    }

    private function payload(User $user, array $parsed, string $serviceType, bool $useBaseFare = false): array
    {
        $branch = $this->branch($user, isset($parsed['branch_id']) ? (int) $parsed['branch_id'] : null);
        $pickupLat = (float) ($branch?->latitude ?: -6.9219);
        $pickupLng = (float) ($branch?->longitude ?: 107.6071);
        $destinationLat = $useBaseFare ? $pickupLat : $pickupLat + 0.018;
        $destinationLng = $useBaseFare ? $pickupLng : $pickupLng + 0.018;

        $payload = [
            'service_type' => $serviceType,
            'pickup_address' => $this->isPurchaseService($serviceType)
                ? ($parsed['store_location'] ?: $parsed['pickup_address'] ?: ($branch?->name ?? 'Lokasi pembelian'))
                : ($parsed['pickup_address'] ?: ($branch?->name ?? 'Lokasi jemput')),
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'destination_address' => $parsed['destination_address'] ?: 'Alamat tujuan',
            'destination_lat' => $destinationLat,
            'destination_lng' => $destinationLng,
            'branch_id' => $branch?->id,
            'stops' => 1,
            'destination_text' => $parsed['destination_address'],
            'notes' => trim(implode("\n", array_filter([
                $parsed['notes'],
                $parsed['name'] ? 'Nama: '.$parsed['name'] : null,
                $parsed['phone'] ? 'No. Hp: '.$parsed['phone'] : null,
            ]))),
            'points' => array_slice($parsed['points'] ?? [], 0, 5),
            'items' => $serviceType === 'belanja' ? $this->shoppingItems((string) ($parsed['notes'] ?? '')) : [],
            'driver_preference' => $serviceType === 'ojek' ? ($parsed['driver_preference'] ?? 'general') : 'general',
            'service_payload' => [
                'source' => 'jojobot_form_parser',
                'driver_preference' => $serviceType === 'ojek' ? ($parsed['driver_preference'] ?? 'general') : 'general',
                'store_location' => $parsed['store_location'] ?? null,
                'location_flow_note' => $this->isPurchaseService($serviceType)
                    ? 'Alamat pembelian dipakai sebagai titik ambil barang; alamat antar wajib mengikuti input customer. Alamat profile hanya untuk validasi pendaftaran.'
                    : 'Alamat jemput dipakai sebagai titik awal; alamat tujuan dipakai sebagai tujuan akhir.',
            ],
        ];

        if ($serviceType === 'travel' && filled($parsed['route'] ?? null)) {
            $payload['route'] = $parsed['route'];
        }

        if ($serviceType === 'joker_mobil') {
            $vehicleSeatRows = ((int) ($parsed['vehicle_seat_rows'] ?? 2)) === 3 ? 3 : 2;
            $payload['preferred_vehicle_type'] = 'mobil';
            $payload['vehicle_seat_rows'] = $vehicleSeatRows;
            $payload['service_payload']['preferred_vehicle_type'] = 'mobil';
            $payload['service_payload']['vehicle_seat_rows'] = $vehicleSeatRows;
        }

        if ($useBaseFare) {
            $payload['distance'] = 0;
            $payload['distance_km'] = 0;
            $payload['service_payload']['geocoding_status'] = 'base_fare';
            $payload['service_payload']['geocoding_warning'] = 'Tarif dasar dipakai karena salah satu alamat memakai fallback customer/cabang.';

            return $payload;
        }

        return $this->hydratePayloadCoordinates($payload, $branch, $user);
    }

    private function hydratePayloadCoordinates(array $payload, ?Branch $branch = null, ?User $user = null): array
    {
        if (isset($payload['distance']) || isset($payload['distance_km'])) {
            return $payload;
        }

        if (! $branch && isset($payload['branch_id'])) {
            $branchId = Branch::resolveOperationalAreaId((int) $payload['branch_id'])
                ?? (int) $payload['branch_id'];
            $branch = Branch::query()->find($branchId);
        }
        $servicePayload = is_array($payload['service_payload'] ?? null) ? $payload['service_payload'] : [];

        $pickupAddress = trim((string) ($payload['pickup_address'] ?? ''));
        $destinationAddress = trim((string) ($payload['destination_address'] ?? $payload['destination_text'] ?? ''));

        $pickupGeo = $this->geocodeForPricing($pickupAddress, $branch, $user)
            ?? $this->pickupPointFromPayload($payload, $pickupAddress, $user);
        $destinationGeo = $this->geocodeForPricing($destinationAddress, $branch, $user);
        $resolved = $pickupGeo !== null && $destinationGeo !== null;

        if (! $resolved) {
            $missing = [];
            if ($pickupGeo === null) {
                $missing[] = 'alamat jemput';
            }
            if ($destinationGeo === null) {
                $missing[] = 'alamat tujuan';
            }

            throw new RuntimeException('Maps belum berhasil membaca '.implode(' dan ', $missing).'. Perjelas alamat atau cek konfigurasi Google Maps API.');
        }

        $payload['pickup_lat'] = $pickupGeo['lat'];
        $payload['pickup_lng'] = $pickupGeo['lng'];
        $payload['destination_lat'] = $destinationGeo['lat'];
        $payload['destination_lng'] = $destinationGeo['lng'];

        $payload['service_payload'] = [
            ...$servicePayload,
            'geocoding_status' => 'resolved',
            'pickup_geocoded_by' => $pickupGeo['provider'] ?? null,
            'destination_geocoded_by' => $destinationGeo['provider'] ?? null,
            'pickup_geocoding_query' => $pickupGeo['query'] ?? null,
            'destination_geocoding_query' => $destinationGeo['query'] ?? null,
            'pickup_formatted_address' => $pickupGeo['formatted_address'] ?? null,
            'destination_formatted_address' => $destinationGeo['formatted_address'] ?? null,
            'pickup_geojson_region_id' => $pickupGeo['geojson_region_id'] ?? null,
            'pickup_geojson_region_name' => $pickupGeo['geojson_region_name'] ?? null,
            'pickup_geojson_area_id' => $pickupGeo['geojson_area_id'] ?? null,
            'pickup_geojson_area_name' => $pickupGeo['geojson_area_name'] ?? null,
            'pickup_ai_alias_map_id' => $pickupGeo['ai_alias_map_id'] ?? null,
            'pickup_ai_alias_canonical_name' => $pickupGeo['ai_alias_canonical_name'] ?? null,
            'destination_geojson_region_id' => $destinationGeo['geojson_region_id'] ?? null,
            'destination_geojson_region_name' => $destinationGeo['geojson_region_name'] ?? null,
            'destination_geojson_area_id' => $destinationGeo['geojson_area_id'] ?? null,
            'destination_geojson_area_name' => $destinationGeo['geojson_area_name'] ?? null,
            'destination_ai_alias_map_id' => $destinationGeo['ai_alias_map_id'] ?? null,
            'destination_ai_alias_canonical_name' => $destinationGeo['ai_alias_canonical_name'] ?? null,
            'geocoding_warning' => null,
        ];

        return $payload;
    }

    private function geocodeForPricing(string $address, ?Branch $branch, ?User $user = null): ?array
    {
        $address = trim($address);
        $normalized = mb_strtolower($address);

        if ($address === '' || in_array($normalized, ['alamat tujuan', 'alamat customer', 'lokasi pembelian', 'lokasi jemput'], true)) {
            return null;
        }

        $memoKey = sha1($normalized.'|'.($branch?->id ?? 'none').'|'.($user?->id ?? 'guest'));
        if (array_key_exists($memoKey, $this->pricingGeocodeMemo)) {
            return $this->pricingGeocodeMemo[$memoKey];
        }

        if ($user && $homeGeocode = $this->customerHomeGeocode($address, $user)) {
            return $this->pricingGeocodeMemo[$memoKey] = $homeGeocode;
        }

        if ($poi = $this->locationPois->resolve($address, $branch?->id)) {
            return $this->pricingGeocodeMemo[$memoKey] = $this->locationPois->geocodeResult($poi);
        }

        if ($regionGeocode = $this->geojsonRegions->geocodeByName($address, $branch?->id)) {
            return $this->pricingGeocodeMemo[$memoKey] = $regionGeocode;
        }

        if ($aliasFallback = $this->geocodeAliasCanonicalForPricing($address, $branch)) {
            return $this->pricingGeocodeMemo[$memoKey] = $aliasFallback;
        }

        if ($this->settings->bool('google_maps_geocode_enabled', false)) {
            $googleResult = $this->geocoding->geocodeNearBranchGoogleOnly(
                $address,
                $branch,
                self::PRICING_GEOCODE_CANDIDATES,
                120,
            );

            if ($googleResult !== null) {
                return $this->pricingGeocodeMemo[$memoKey] = $googleResult;
            }
        }

        return $this->pricingGeocodeMemo[$memoKey] = $this->geocoding->geocodeNearBranchLimited(
            $address,
            $branch,
            self::PRICING_GEOCODE_CANDIDATES,
            120,
        );
    }

    private function geocodeAliasCanonicalForPricing(string $address, ?Branch $branch): ?array
    {
        $alias = $this->aliasMaps->resolve($address, $branch?->id);
        if ($alias === null) {
            return null;
        }

        $canonical = trim((string) $alias->canonical_name);
        if ($canonical === '' || $this->locationPois->normalize($canonical) === $this->locationPois->normalize($address)) {
            return null;
        }

        $result = $this->geocoding->geocodeNearBranchLimited(
            $canonical,
            $branch,
            self::PRICING_GEOCODE_CANDIDATES,
            120,
        );

        if ($result === null) {
            return null;
        }

        $alias->forceFill([
            'hit_count' => $alias->hit_count + 1,
            'last_used_at' => now(),
        ])->save();

        return [
            ...$result,
            'provider' => 'ai_alias_canonical_geocode',
            'ai_alias_map_id' => $alias->id,
            'ai_alias_canonical_name' => $alias->canonical_name,
            'query' => $result['query'] ?? $canonical,
        ];
    }

    private function pickupPointFromPayload(array $payload, string $address, ?User $user): ?array
    {
        $lat = $payload['pickup_lat'] ?? null;
        $lng = $payload['pickup_lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $normalizedAddress = $this->locationPois->normalize($address);
        $normalizedUserAddress = $this->locationPois->normalize((string) ($user?->address ?? ''));
        $isUserAddress = $normalizedUserAddress !== '' && $normalizedAddress === $normalizedUserAddress;

        if (($payload['service_payload']['source'] ?? null) !== 'smart_parser' || (! $this->isCustomerHomeAddress($address) && ! $isUserAddress)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => $address,
            'provider' => 'payload_pickup_point',
            'confidence' => 80,
            'query' => $address,
        ];
    }

    private function customerHomeGeocode(string $address, User $user): ?array
    {
        $lat = $user->lat ?? $user->currentLocation?->lat;
        $lng = $user->lng ?? $user->currentLocation?->lng;

        if (! $this->isCustomerHomeAddress($address) || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'formatted_address' => $user->address ?: 'Rumah saya',
            'provider' => 'customer_profile_home',
            'confidence' => 95,
            'query' => $address,
        ];
    }

    private function isCustomerHomeAddress(string $address): bool
    {
        $normalized = $this->locationPois->normalize($address);

        if (in_array($normalized, ['rumah', 'rumah saya', 'rumahku', 'alamat saya', 'alamat rumah', 'lokasi saya', 'lokasi rumah', 'lokasi jemput saya', 'home'], true)) {
            return true;
        }

        return preg_match('/\b(?:rumah|home)\b/u', $normalized) === 1
            && preg_match('/\b(?:saya|ku|sendiri)\b/u', $normalized) === 1;
    }

    private function geocodeCandidates(string $address, ?Branch $branch): array
    {
        $localAliases = $this->localGeocodeAliases($address, $branch);
        $localCandidates = $this->localGeocodeCandidates($address, $branch);
        $withBranchContext = implode(', ', array_values(array_unique(array_filter([
            $address,
            $branch?->area,
            $branch?->name,
            'Indonesia',
        ]))));

        return collect([...$localAliases, ...$localCandidates, $withBranchContext, $address])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function localGeocodeCandidates(string $address, ?Branch $branch): array
    {
        $address = trim($address);
        if ($address === '' || ! $branch) {
            return [];
        }

        $branchName = trim((string) $branch->name);
        $branchArea = trim((string) $branch->area);
        if ($branchName === '' && $branchArea === '') {
            return [];
        }

        return collect([
            $branchName !== '' ? "{$address} {$branchName}, Indonesia" : null,
            $branchName !== '' ? "{$address}, {$branchName}, Indonesia" : null,
            $branchArea !== '' && $branchName !== '' ? "{$address}, {$branchName}, {$branchArea}, Indonesia" : null,
            $branchArea !== '' && $branchName !== '' ? "{$address}, {$branchArea}, {$branchName}, Indonesia" : null,
        ])
            ->filter()
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    private function localGeocodeAliases(string $address, ?Branch $branch): array
    {
        $normalized = str($address)
            ->lower()
            ->replaceMatches('/\b(?:depan|belakang|samping|seberang|dekat|dkt|arah|menuju)\b/u', ' ')
            ->squish()
            ->toString();

        $branchHints = collect([$branch?->name, $branch?->area])
            ->filter()
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values();

        if ($branchHints->isEmpty()) {
            return [];
        }

        $aliases = [];

        if (preg_match('/\brsud\b/u', $normalized) === 1) {
            foreach ($branchHints as $hint) {
                $aliases[] = 'rsud '.$hint;
            }
        }

        if (preg_match('/\brs\b/u', $normalized) === 1) {
            foreach ($branchHints as $hint) {
                $aliases[] = 'rumah sakit '.$hint;
            }
        }

        if (preg_match('/\bterminal\b/u', $normalized) === 1) {
            foreach ($branchHints as $hint) {
                $aliases[] = 'terminal '.$hint;
            }
        }

        return collect($aliases)
            ->map(fn (string $value): string => trim($value.', Indonesia'))
            ->unique()
            ->values()
            ->all();
    }

    private function geocodingContext(?Branch $branch): array
    {
        if (! $branch || ! is_numeric($branch->latitude) || ! is_numeric($branch->longitude)) {
            return [];
        }

        return [
            'lat' => (float) $branch->latitude,
            'lng' => (float) $branch->longitude,
            'radius_km' => $this->geocodingRadiusKm($branch),
        ];
    }

    private function geocodingRadiusKm(Branch $branch): float
    {
        if (! is_numeric($branch->radius_km ?? null)) {
            return 10;
        }

        return min(25, max(3, (float) $branch->radius_km));
    }

    private function isGeocodeTooFarFromBranch(array $result, ?Branch $branch): bool
    {
        if (! $branch || ! is_numeric($result['distance_from_bias_km'] ?? null)) {
            return false;
        }

        $allowedKm = is_numeric($branch->radius_km ?? null)
            ? max(3, (float) $branch->radius_km + 0.5)
            : 10;

        return (float) $result['distance_from_bias_km'] > min($allowedKm, 25);
    }

    private function shoppingItems(string $text): array
    {
        return collect(explode(',', $text))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->map(fn (string $item): array => ['name' => $item, 'quantity' => 1])
            ->values()
            ->all();
    }

    private function branch(User $user, ?int $branchId = null): ?Branch
    {
        if ($branchId) {
            $branch = Branch::query()->find($branchId);
            if ($branch) {
                return $branch;
            }
        }

        if ($user->branch_id) {
            $branchId = Branch::resolveOperationalAreaId((int) $user->branch_id, (float) $user->lat ?: null, (float) $user->lng ?: null)
                ?? (int) $user->branch_id;

            return Branch::query()->find($branchId);
        }

        return Branch::query()->operationalAreas()->whereNotNull('latitude')->whereNotNull('longitude')->first();
    }

    private function branchIdFromText(?string $value): ?int
    {
        $needle = str($value ?? '')
            ->lower()
            ->replace(['-', '_', '/', ','], ' ')
            ->squish()
            ->toString();

        if ($needle === '') {
            return null;
        }

        $matches = Branch::query()
            ->operationalAreas()
            ->get(['id', 'branch_code', 'name', 'area'])
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

        return Branch::resolveOperationalAreaId((int) $topBranchIds->first())
            ?? (int) $topBranchIds->first();
    }

    private function serviceType(string $code, string $name): string
    {
        return match (mb_strtoupper($code)) {
            'OJ' => 'ojek',
            'KR' => 'kurir',
            'DO' => 'delivery',
            'BL' => 'belanja',
            'GO' => 'gift_order',
            'TV' => 'travel',
            'JM' => 'joker_mobil',
            default => str($name)->lower()->replace(' ', '_')->toString(),
        };
    }

    private function normalizeRequestedService(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return match ($value) {
            'oj', 'ojek' => 'ojek',
            'kr', 'kurir' => 'kurir',
            'do', 'delivery', 'delivery order' => 'delivery',
            'bl', 'belanja' => 'belanja',
            'go', 'gift', 'gift order', 'gift_order' => 'gift_order',
            'tv', 'travel' => 'travel',
            'jm', 'joker', 'joker mobil', 'joker_mobil' => 'joker_mobil',
            default => str($value)->replace(['-', ' '], '_')->toString(),
        };
    }

    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }

            if (mb_strlen($keyword) <= 3) {
                if (preg_match('/(^|[^\pL\pN])'.preg_quote($keyword, '/').'([^\pL\pN]|$)/iu', $text) === 1) {
                    return true;
                }

                continue;
            }

            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isGiftOrder(string $serviceType): bool
    {
        return in_array(strtolower($serviceType), ['gift', 'gift_order', 'go'], true);
    }

    private function isOutsideAreaServiceType(string $serviceType, Collection $services): bool
    {
        $normalized = $this->normalizeRequestedService($serviceType) ?? strtolower($serviceType);

        return $services->contains(function (array $service) use ($normalized): bool {
            $serviceType = $this->normalizeRequestedService((string) ($service['service_type'] ?? $service['code'] ?? $service['name'] ?? ''));

            return $serviceType === $normalized && ((bool) ($service['outside_area_only'] ?? false) || $this->isGiftOrder($serviceType));
        });
    }

    private function defaultFormSchema(string $serviceType): ?array
    {
        if ($this->isPurchaseService($serviceType)) {
            return [
                'fields' => [
                    ['label' => 'Nama', 'name' => 'nama', 'type' => 'text', 'required' => false, 'options' => []],
                    ['label' => 'Hp / WhatsApp', 'name' => 'phone', 'type' => 'phone', 'required' => false, 'options' => []],
                    ['label' => 'Alamat Antar', 'name' => 'alamat_antar', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Lokasi Pembelian', 'name' => 'lokasi_pembelian', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Pembelian', 'name' => 'belikan', 'type' => 'textarea', 'required' => true, 'options' => []],
                ],
            ];
        }

        if ($serviceType === 'ojek') {
            return [
                'fields' => [
                    ['label' => 'Alamat Jemput', 'name' => 'alamat_jemput', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Alamat Antar', 'name' => 'alamat_antar', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Jumlah Penumpang', 'name' => 'jumlah_penumpang', 'type' => 'number', 'required' => false, 'options' => []],
                    ['label' => 'Catatan', 'name' => 'catatan', 'type' => 'textarea', 'required' => false, 'options' => []],
                ],
            ];
        }

        if ($serviceType === 'kurir') {
            return [
                'fields' => [
                    ['label' => 'Alamat Jemput', 'name' => 'alamat_jemput', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Alamat Tujuan', 'name' => 'alamat_tujuan', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Barang', 'name' => 'barang', 'type' => 'text', 'required' => true, 'options' => []],
                    ['label' => 'Catatan', 'name' => 'catatan', 'type' => 'textarea', 'required' => false, 'options' => []],
                ],
            ];
        }

        return null;
    }

    private function isPurchaseService(string $serviceType): bool
    {
        return in_array(strtolower($serviceType), ['do', 'delivery', 'belanja', 'gift_order', 'gift'], true);
    }

    private function isOutsideRegisteredArea(User $user): bool
    {
        if ($user->branch_id === null) {
            return true;
        }

        $latest = $user->latestLocationLog;

        return $latest !== null && ! $latest->is_valid;
    }

    private function giftOrderDirection(Collection $services, array $parsed): array
    {
        $outsideAreaServices = $services
            ->filter(fn (array $service): bool => (bool) ($service['outside_area_only'] ?? false) || $this->isGiftOrder((string) ($service['service_type'] ?? $service['code'] ?? $service['name'] ?? '')))
            ->values();

        return [
            'intent' => 'service_selected',
            'services' => $outsideAreaServices->isNotEmpty() ? $outsideAreaServices : $services->values(),
            'selected_service' => $outsideAreaServices->first()['service_type'] ?? 'gift_order',
            'parsed' => $parsed,
            'quote' => null,
            'order_payload' => null,
            'reply' => "Area kamu berada di luar cabang aktif. JOJOBOT hanya menampilkan layanan khusus luar area.\n\nSilakan pilih layanan yang tersedia di chat.",
        ];
    }

    private function missingPurchaseLocationResponse(Collection $services, string $serviceType, array $parsed, ?array $formSchema = null): array
    {
        return [
            'intent' => 'service_selected',
            'services' => $services->values(),
            'selected_service' => $serviceType,
            'parsed' => [
                ...$parsed,
                'missing_fields' => ['Lokasi Pembelian'],
            ],
            'message' => "Lokasi Pembelian belum terbaca.\nIsi nama toko/resto/warung/pasar di field Lokasi Pembelian, jangan isi dengan nama barang.",
            'form_schema' => $formSchema,
            'service_type' => $serviceType,
            'quote' => null,
            'order_payload' => null,
            'reply' => "Lokasi Pembelian belum terbaca.\nIsi nama toko/resto/warung/pasar di field Lokasi Pembelian, jangan isi dengan nama barang.",
        ];
    }

    private function missingDestinationResponse(Collection $services, string $serviceType, array $parsed, ?array $formSchema = null): array
    {
        $label = $this->isPurchaseService($serviceType) ? 'Alamat Antar' : 'Alamat Tujuan';

        return [
            'intent' => 'service_selected',
            'services' => $services->values(),
            'selected_service' => $serviceType,
            'parsed' => [
                ...$parsed,
                'missing_fields' => [$label],
            ],
            'message' => $label." belum terbaca.\nIsi alamat sesuai tujuan order customer. Alamat profile hanya dipakai untuk validasi akun, bukan pengganti alamat order.",
            'form_schema' => $formSchema,
            'service_type' => $serviceType,
            'quote' => null,
            'order_payload' => null,
            'reply' => $label." belum terbaca.\nIsi alamat sesuai tujuan order customer. Alamat profile hanya dipakai untuk validasi akun, bukan pengganti alamat order.",
        ];
    }

    private function serviceMenuReply(Collection $services): string
    {
        return "Pilih layanan:\n".$services->values()->map(fn ($service, $index): string => ($index + 1).'. '.$service['name'])->implode("\n");
    }

    private function orderPreviewReply(array $parsed, array $quote): string
    {
        $money = fn (int|float|null $value): string => 'Rp '.number_format((int) $value, 0, ',', '.');
        $serviceType = (string) ($parsed['service_type'] ?? '');
        $pickupLabel = $this->isPurchaseService($serviceType) ? 'Lokasi pembelian' : 'Alamat jemput';
        $destinationLabel = $this->isPurchaseService($serviceType) ? 'Alamat antar' : 'Tujuan';
        $pickupText = $this->isPurchaseService($serviceType)
            ? ($parsed['store_location'] ?? $parsed['pickup_address'] ?? '-')
            : ($parsed['pickup_address'] ?? '-');

        $helperFee = (int) ($quote['crew_helper_fee'] ?? $quote['helper_service_charge'] ?? data_get($quote, 'crew_decision.helper_fee', data_get($quote, 'crew_decision.helper_service_charge', 0)));
        $helperLabel = (string) data_get($quote, 'crew_decision.helper_label', 'Jasa helper');
        $distance = $quote['distance_km'] ?? $quote['distance'] ?? null;
        $distanceLabel = is_numeric($distance)
            ? number_format((float) $distance, 2, ',', '.').' km'
            : '-';
        $ringLabel = filled($quote['ring'] ?? null)
            ? strtoupper(str_replace('_', ' ', (string) $quote['ring']))
            : null;

        $breakdown = [
            'Pesanan Anda:',
            '- Nama: '.($parsed['name'] ?: '-'),
            '- '.$pickupLabel.': '.$pickupText,
            '- '.$destinationLabel.': '.($parsed['destination_address'] ?: '-'),
            '',
            'Breakdown:',
            '- Jarak hitung: '.$distanceLabel,
        ];

        if ($ringLabel !== null) {
            $breakdown[] = '- Ring: '.$ringLabel;
        }

        if (filled($quote['cross_ring'] ?? null)) {
            $breakdown[] = '- Cross ring: '.strtoupper(str_replace('_', ' ', (string) $quote['cross_ring']));
        }

        $breakdown = [
            ...$breakdown,
            '- Tarif: '.$money($quote['tarif'] ?? $quote['price'] ?? 0),
            '- Service fee: '.$money($quote['service_fee'] ?? $quote['service_charge'] ?? 0),
            '- Tambahan: '.$money($quote['extra_charge'] ?? 0),
        ];

        if ($helperFee > 0) {
            $breakdown[] = '- '.$helperLabel.': '.$money($helperFee);
        }

        return implode("\n", [
            ...$breakdown,
            '',
            'Total: '.$money($quote['total_price'] ?? $quote['final_price'] ?? 0),
            '',
            'Apakah pesanan sudah benar? (YA / TIDAK)',
        ]);
    }
}
