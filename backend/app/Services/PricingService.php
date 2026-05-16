<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Models\PriceSetting;
use App\Services\Pricing\DistanceCalculator;
use App\Services\Pricing\JokerPricing;
use App\Services\Pricing\ServiceFeeCalculator;
use App\Services\Pricing\TravelPricing;
use App\Services\Spatial\GeojsonRegionLookupService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class PricingService
{
    private const SPECIAL_KEYWORD_CHARGE = 2000;
    private const SPECIAL_KEYWORDS = ['rs'];
    private const PURCHASE_GACOAN_CHARGE = 2000;
    private const PURCHASE_AREA_CHARGE = 3000;
    private const PURCHASE_AREA_KEYWORDS = ['pasar', 'roxy', 'royal', 'swalayan'];
    private const PURCHASE_CHARGE_SERVICES = ['belanja', 'gift_order', 'kurir'];
    private const GENERAL_SERVICES = ['ojek', 'kurir', 'delivery', 'do', 'belanja', 'gift', 'gift_order'];

    public function __construct(
        private readonly DistanceCalculator $distanceCalculator,
        private readonly ServiceFeeCalculator $serviceFeeCalculator,
        private readonly JokerPricing $jokerPricing,
        private readonly TravelPricing $travelPricing,
        private readonly PricingKeywordRuleService $keywordRules,
        private readonly OrderOperationService $operations,
        private readonly RingPricingService $ringPricing,
        private readonly ZonePricingService $zonePricing,
        private readonly BranchDetectionService $branches,
        private readonly OrderCrewDecisionService $crewDecisions,
        private readonly GeojsonRegionLookupService $geojsonRegions,
    ) {
    }

    public function calculate(array|string $payloadOrServiceType, ?float $distance = null, int $stops = 1, string|array|null $route = null): array
    {
        if (is_array($payloadOrServiceType)) {
            return $this->calculateFromPayload($payloadOrServiceType);
        }

        return $this->calculateByDistance($payloadOrServiceType, (float) $distance, $stops, $route);
    }

    public function calculateByDistance(string $serviceType, float $distance, int $stops = 1, string|array|null $route = null): array
    {
        $serviceType = $this->normalizeServiceType($serviceType);
        $this->validate($serviceType, $distance, $stops);

        return match ($serviceType) {
            'joker_mobil' => $this->calculateJokerMobil($serviceType, $distance, $stops),
            'travel' => $this->calculateTravel($serviceType, $distance, $stops, $route),
            default => $this->calculateGeneral($serviceType, $distance, $stops),
        };
    }

    public function calculatePrice(Branch|int $branch, float $destLat, float $destLon, int $stops, ?string $notes = null, ?string $destinationText = null): array
    {
        $branchModel = $branch instanceof Branch ? $branch : Branch::query()->findOrFail($branch);

        return $this->calculate([
            'service_type' => 'ojek',
            'branch_id' => $branchModel->id,
            'pickup_address' => $branchModel->name,
            'pickup_lat' => $branchModel->latitude,
            'pickup_lng' => $branchModel->longitude,
            'destination_address' => $destinationText ?: 'Destination',
            'destination_lat' => $destLat,
            'destination_lng' => $destLon,
            'destination_text' => $destinationText,
            'stops' => $stops,
            'notes' => $notes,
        ]);
    }

    public function distanceWithOsrmFallback(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->distanceCalculator->drivingDistance($lat1, $lng1, $lat2, $lng2);
    }

    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->distanceCalculator->drivingDistance($lat1, $lng1, $lat2, $lng2);
    }

    public function distanceInKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->calculateDistance($lat1, $lng1, $lat2, $lng2);
    }

    public function calculateTarifFromDatabase(float $distanceKm, ?int $branchId = null): int
    {
        $distanceKm = max(0.0, $distanceKm);
        $baseQuery = PriceSetting::query()
            ->active()
            ->forBranch($branchId);

        $setting = (clone $baseQuery)
            ->forDistance($distanceKm)
            ->orderByRaw('branch_id IS NULL')
            ->orderByRaw('max_km IS NULL')
            ->orderByDesc('min_km')
            ->first();

        if (! $setting && $distanceKm <= 0) {
            $setting = (clone $baseQuery)
                ->where('min_km', '>', 0)
                ->orderByRaw('branch_id IS NULL')
                ->orderBy('min_km')
                ->first();
        }

        if (! $setting) {
            throw new RuntimeException('Distance Price Settings aktif belum tersedia untuk jarak '.round($distanceKm, 2).' KM.');
        }

        if (! $setting->is_formula) {
            if ($setting->price === null) {
                throw new RuntimeException('Price setting requires price when is_formula is false.');
            }

            return (int) $setting->price;
        }

        if ($setting->per_km_rate === null) {
            throw new RuntimeException('Price setting requires per_km_rate when is_formula is true.');
        }

        $price = ($distanceKm * (int) $setting->per_km_rate) - (int) $setting->subtract_value;

        return max(0, (int) ceil($price));
    }

    public function calculateServiceFee(int $stops): int
    {
        return $this->serviceFeeCalculator->calculate($stops);
    }

    public function serviceFeeBreakdown(int $stops): array
    {
        return $this->serviceFeeCalculator->breakdown($stops);
    }

    public function applySpecialCharge(array|string|null $text): int
    {
        return $this->extraServiceCharge($text);
    }

    public function extraServiceCharge(array|string|null $text): int
    {
        $haystack = mb_strtolower($this->textFromMixed($text));

        foreach (self::SPECIAL_KEYWORDS as $keyword) {
            if ($keyword === 'rs') {
                if (preg_match('/(^|[^\pL\pN])rs([^\pL\pN]|$)/u', $haystack) === 1) {
                    return self::SPECIAL_KEYWORD_CHARGE;
                }

                continue;
            }

            if (str_contains($haystack, $keyword)) {
                return self::SPECIAL_KEYWORD_CHARGE;
            }
        }

        return 0;
    }

    public function extraServiceChargeForService(string $serviceType, array|string|null $text): int
    {
        $databaseCharge = $this->keywordRules->calculate($serviceType, $text);
        if ($databaseCharge !== null) {
            return (int) $databaseCharge['amount'];
        }

        $baseCharge = $this->extraServiceCharge($text);
        $serviceType = $this->normalizeServiceType($serviceType);

        if (! in_array($serviceType, self::PURCHASE_CHARGE_SERVICES, true)) {
            return $baseCharge;
        }

        $haystack = mb_strtolower($this->textFromMixed($text));
        $charge = $baseCharge;

        if (str_contains($haystack, 'gacoan')) {
            $charge += self::PURCHASE_GACOAN_CHARGE;
        }

        foreach (self::PURCHASE_AREA_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $charge += self::PURCHASE_AREA_CHARGE;
                break;
            }
        }

        return $charge;
    }

    public function roundUpPrice(int|float $price): int
    {
        return (int) (ceil(((float) $price) / 1000) * 1000);
    }

    private function calculateFromPayload(array $payload): array
    {
        $serviceType = $this->normalizeServiceType((string) ($payload['service_type'] ?? 'ojek'));
        $stops = max(1, (int) ($payload['stops'] ?? $payload['stop_count'] ?? 1));
        $route = $payload['route'] ?? $payload['travel_route'] ?? $payload['service_payload']['route'] ?? null;

        $pricingBranch = $this->resolvePricingBranch($payload);
        $pricingBranchId = $pricingBranch?->id ?? (isset($payload['branch_id']) ? (int) $payload['branch_id'] : null);
        if ($pricingBranchId !== null) {
            $payload['branch_id'] = $pricingBranchId;
        }
        $geojsonRegion = $this->detectDestinationRegion($payload);
        if ($geojsonRegion?->branch_id) {
            $pricingBranch = $geojsonRegion->branch;
            $pricingBranchId = (int) $geojsonRegion->branch_id;
            $payload['branch_id'] = $pricingBranchId;
        }

        $routeDistance = $this->resolveDistanceResult($payload, $pricingBranch, $serviceType);
        $distance = (float) $routeDistance['distance_km'];
        $this->validate($serviceType, $distance, $stops);

        if ($pricingBranch && $this->ringPricing->hasActiveCrossRules($serviceType, $pricingBranchId)) {
            $payload = [
                ...$payload,
                ...$this->endpointDistancesFromBranchCenter($payload, $pricingBranch),
            ];
        }
        $distanceFromBranch = $distance;
        $masterRingMatch = ! in_array($serviceType, ['joker_mobil', 'travel'], true)
            ? $this->ringPricing->matchMasterDistance($payload, $serviceType, $pricingBranchId, $distanceFromBranch)
            : null;

        if ($masterRingMatch) {
            $quote = $this->response([
                'service_type' => $serviceType,
                'distance' => $distance,
                'tarif' => 0,
                'tarif_source' => 'master_ring_distance',
                'service_charge' => 0,
                'total_before_round' => 0,
                'final_price' => 0,
                'stops' => $stops,
                'branch_id' => $pricingBranchId,
                'branch_code' => $pricingBranch?->branch_code,
                'branch_name' => $pricingBranch?->display_name,
                'geojson_region_id' => $geojsonRegion?->id,
                'geojson_region_name' => $geojsonRegion?->name,
                'geojson_area_id' => $geojsonRegion?->area_id,
                'geojson_area_name' => $geojsonRegion?->area?->name,
                'distance_from_branch_km' => $distanceFromBranch,
                'routing_provider' => $routeDistance['provider'],
                'routing_fallback_used' => $routeDistance['fallback_used'],
                'pricing_distance_origin' => $routeDistance['pricing_origin'] ?? 'pickup',
                'pricing_origin_branch_id' => $routeDistance['pricing_origin_branch_id'] ?? null,
                'pricing_origin_name' => $routeDistance['pricing_origin_name'] ?? null,
                'pricing_origin_lat' => $routeDistance['pricing_origin_lat'] ?? null,
                'pricing_origin_lng' => $routeDistance['pricing_origin_lng'] ?? null,
                'pickup_outside_pricing_branch' => $routeDistance['pickup_outside_pricing_branch'] ?? false,
                'service_fee_breakdown' => [['point' => 1, 'label' => 'Master Ring service fee', 'fee' => (int) ($masterRingMatch['rule']->service_fee ?? 0)]],
            ]);
            $quote = $this->ringPricing->applyMaster($quote, $masterRingMatch['rule'], $masterRingMatch);
        } else {
            $quote = match ($serviceType) {
                'joker_mobil' => $this->calculateJokerMobil($serviceType, $distance, $stops),
                'travel' => $this->calculateTravel($serviceType, $distance, $stops, $route),
                default => $this->calculateGeneral($serviceType, $distance, $stops, $pricingBranchId),
            };

            if ($geojsonRegion) {
                $quote['geojson_region_id'] = $geojsonRegion->id;
                $quote['geojson_region_name'] = $geojsonRegion->name;
                $quote['geojson_area_id'] = $geojsonRegion->area_id;
                $quote['geojson_area_name'] = $geojsonRegion->area?->name;
                $quote['branch_id'] = $pricingBranchId;
                $quote['branch_name'] = $pricingBranch?->display_name;
            }

            if ($ringRule = $this->ringPricing->match($payload, $serviceType)) {
                $quote = $this->ringPricing->apply($quote, $ringRule);
            }
        }

        $quote['routing_provider'] = $routeDistance['provider'];
        $quote['routing_fallback_used'] = $routeDistance['fallback_used'];
        $quote['pricing_distance_origin'] = $routeDistance['pricing_origin'] ?? 'pickup';
        $quote['pricing_origin_branch_id'] = $routeDistance['pricing_origin_branch_id'] ?? null;
        $quote['pricing_origin_name'] = $routeDistance['pricing_origin_name'] ?? null;
        $quote['pricing_origin_lat'] = $routeDistance['pricing_origin_lat'] ?? null;
        $quote['pricing_origin_lng'] = $routeDistance['pricing_origin_lng'] ?? null;
        $quote['pickup_outside_pricing_branch'] = $routeDistance['pickup_outside_pricing_branch'] ?? false;

        $extraCharge = $this->extraServiceChargeForService($serviceType, [
            $payload['pickup_address'] ?? '',
            $payload['store_location'] ?? '',
            $payload['purchase_address'] ?? '',
            $payload['destination_text'] ?? $payload['destination_address'] ?? '',
            $payload['notes'] ?? '',
            $payload['service_payload'] ?? [],
            $payload['items'] ?? [],
        ]);

        if ($extraCharge !== 0 && $serviceType !== 'travel') {
            $quote['extra_charge'] = $extraCharge;
            $quote['keyword_charge'] = $extraCharge;
            $quote['service_charge'] += $extraCharge;
            $quote['total_before_round'] += $extraCharge;
            $quote['total_before_round'] = max(0, (int) $quote['total_before_round']);
            $quote['subtotal'] = $quote['total_before_round'];
            $quote['final_price'] = $this->roundUpPrice($quote['total_before_round']);
            $quote['total_price'] = $quote['final_price'];

            Log::info('pricing.keyword_charge_active', [
                'service_type' => $serviceType,
                'branch_id' => $payload['branch_id'] ?? null,
                'destination' => $payload['destination_text'] ?? $payload['destination_address'] ?? null,
            ]);
        }

        $baseTarifBeforeNight = (int) ($quote['tarif'] ?? $quote['price'] ?? 0);
        $night = $this->operations->nightTariff($baseTarifBeforeNight, isset($payload['branch_id']) ? (int) $payload['branch_id'] : null);
        if ($night['amount'] > 0) {
            $baseTotalBeforeNight = (int) ($quote['total_before_round'] ?? $quote['subtotal'] ?? $baseTarifBeforeNight);
            $nightTotal = $this->roundUpPrice($baseTotalBeforeNight * (1 + ((int) $night['percent'] / 100)));
            $nightAmount = max(0, $nightTotal - $baseTotalBeforeNight);
            $quote['base_tarif_before_night'] = $baseTarifBeforeNight;
            $quote['base_total_before_night'] = $baseTotalBeforeNight;
            $quote['night_tariff_charge'] = $nightAmount;
            $quote['night_tariff_percent'] = (int) $night['percent'];
            $quote['tarif'] += $nightAmount;
            $quote['price'] = $quote['tarif'];
            $quote['base_price'] = $quote['tarif'];
            $quote['total_before_round'] = $nightTotal;
            $quote['subtotal'] = $quote['total_before_round'];
            $quote['final_price'] = $nightTotal;
            $quote['total_price'] = $quote['final_price'];
        }

        if ($crewDecision = $this->crewDecisions->decide($payload)) {
            $quote = $this->crewDecisions->applyHelperPricingToQuote($quote, $crewDecision);
        }

        return $quote;
    }

    private function calculateGeneral(string $serviceType, float $distance, int $stops, ?int $branchId = null): array
    {
        $tarif = $this->calculateTarifFromDatabase($distance, $branchId);
        $serviceCharge = $this->calculateServiceFee($stops);
        $totalBeforeRound = $tarif + $serviceCharge;
        $finalPrice = $this->roundUpPrice($totalBeforeRound);

        return $this->response([
            'service_type' => $serviceType,
            'distance' => $distance,
            'tarif' => $tarif,
            'tarif_source' => 'price_settings',
            'service_charge' => $serviceCharge,
            'total_before_round' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'stops' => $stops,
            'service_fee_breakdown' => $this->serviceFeeBreakdown($stops),
        ]);
    }

    private function calculateJokerMobil(string $serviceType, float $distance, int $stops): array
    {
        $joker = $this->jokerPricing->calculate($distance);
        $tarif = $joker['tarif'];
        $totalBeforeRound = $tarif;

        return $this->response([
            'service_type' => $serviceType,
            'distance' => $distance,
            'billing_distance' => $joker['billing_distance'],
            'tarif' => $tarif,
            'service_charge' => 0,
            'total_before_round' => $totalBeforeRound,
            'final_price' => $this->roundUpPrice($totalBeforeRound),
            'stops' => $stops,
            'service_fee_breakdown' => [],
        ]);
    }

    private function calculateTravel(string $serviceType, float $pickupDistance, int $stops, string|array|null $route): array
    {
        if ($route === null) {
            throw new InvalidArgumentException('Rute wajib diisi untuk layanan travel.');
        }

        $travel = $this->travelPricing->calculate($route, $pickupDistance);
        $totalBeforeRound = $travel['tarif'];

        return $this->response([
            'service_type' => $serviceType,
            'distance' => $pickupDistance,
            'tarif' => $travel['tarif'],
            'service_charge' => 0,
            'total_before_round' => $totalBeforeRound,
            'final_price' => $this->roundUpPrice($totalBeforeRound),
            'stops' => $stops,
            'route' => $travel['route'],
            'route_tarif' => $travel['route_tarif'],
            'pickup_distance' => $travel['pickup_distance'],
            'pickup_charge' => $travel['pickup_charge'],
            'service_fee_breakdown' => [],
        ]);
    }

    private function response(array $data): array
    {
        $distance = round((float) $data['distance'], 2);
        $tarif = (int) $data['tarif'];
        $serviceCharge = (int) $data['service_charge'];
        $totalBeforeRound = (int) $data['total_before_round'];
        $finalPrice = (int) $data['final_price'];

        return [
            ...$data,
            'distance' => $distance,
            'distance_km' => $distance,
            'tarif' => $tarif,
            'price' => $tarif,
            'base_price' => $tarif,
            'service_charge' => $serviceCharge,
            'service_fee' => $serviceCharge,
            'extra_charge' => $data['extra_charge'] ?? 0,
            'keyword_charge' => $data['keyword_charge'] ?? 0,
            'subtotal' => $totalBeforeRound,
            'total_before_round' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'total_price' => $finalPrice,
        ];
    }

    private function resolvePricingBranch(array $payload): ?Branch
    {
        if (isset($payload['branch_id']) && $payload['branch_id']) {
            $branchId = Branch::resolveOperationalAreaId((int) $payload['branch_id'])
                ?? (int) $payload['branch_id'];

            return Branch::query()->find($branchId);
        }

        foreach ([
            ['destination_lat', 'destination_lng'],
            ['dropoff_lat', 'dropoff_lng'],
            ['pickup_lat', 'pickup_lng'],
            ['origin_lat', 'origin_lng'],
        ] as [$latKey, $lngKey]) {
            $lat = data_get($payload, $latKey);
            $lng = data_get($payload, $lngKey);
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $branch = $this->branches->detect((float) $lat, (float) $lng)['branch'] ?? null;
            if ($branch instanceof Branch) {
                return $branch;
            }
        }

        return null;
    }

    private function detectDestinationRegion(array $payload): ?GeojsonRegion
    {
        foreach ([
            'service_payload.destination_geojson_region_id',
            'destination_geojson_region_id',
            'geojson_region_id',
        ] as $regionKey) {
            $regionId = data_get($payload, $regionKey);
            if (! is_numeric($regionId)) {
                continue;
            }

            $region = GeojsonRegion::query()
                ->with(['branch', 'area'])
                ->active()
                ->find((int) $regionId);

            if ($region instanceof GeojsonRegion) {
                return $region;
            }
        }

        foreach ([
            ['destination_lat', 'destination_lng'],
            ['dropoff_lat', 'dropoff_lng'],
            ['service_payload.destination_lat', 'service_payload.destination_lng'],
            ['service_payload.dropoff_lat', 'service_payload.dropoff_lng'],
        ] as [$latKey, $lngKey]) {
            $lat = data_get($payload, $latKey);
            $lng = data_get($payload, $lngKey);
            if (is_numeric($lat) && is_numeric($lng)) {
                return $this->geojsonRegions->detect((float) $lat, (float) $lng);
            }
        }

        return null;
    }

    private function distanceFromBranchCenter(array $payload, Branch $branch): ?float
    {
        $origin = $branch->pricingOriginPoint();
        if ($origin === null) {
            return null;
        }

        foreach ([
            ['destination_lat', 'destination_lng'],
            ['dropoff_lat', 'dropoff_lng'],
            ['pickup_lat', 'pickup_lng'],
            ['origin_lat', 'origin_lng'],
        ] as [$latKey, $lngKey]) {
            $lat = data_get($payload, $latKey);
            $lng = data_get($payload, $lngKey);
            if (is_numeric($lat) && is_numeric($lng)) {
                return $this->distanceCalculator->haversine(
                    $origin['lat'],
                    $origin['lng'],
                    (float) $lat,
                    (float) $lng,
                );
            }
        }

        return null;
    }

    private function endpointDistancesFromBranchCenter(array $payload, Branch $branch): array
    {
        return array_filter([
            'pickup_distance_from_branch_km' => $this->endpointDistanceFromBranchCenter($payload, $branch, 'pickup'),
            'destination_distance_from_branch_km' => $this->endpointDistanceFromBranchCenter($payload, $branch, 'destination'),
        ], fn ($value): bool => $value !== null);
    }

    private function endpointDistanceFromBranchCenter(array $payload, Branch $branch, string $type): ?float
    {
        $origin = $branch->pricingOriginPoint();
        if ($origin === null) {
            return null;
        }

        $keys = $type === 'pickup'
            ? [
                ['pickup_lat', 'pickup_lng'],
                ['origin_lat', 'origin_lng'],
                ['service_payload.pickup_lat', 'service_payload.pickup_lng'],
                ['service_payload.origin_lat', 'service_payload.origin_lng'],
            ]
            : [
                ['destination_lat', 'destination_lng'],
                ['dropoff_lat', 'dropoff_lng'],
                ['service_payload.destination_lat', 'service_payload.destination_lng'],
                ['service_payload.dropoff_lat', 'service_payload.dropoff_lng'],
            ];

        foreach ($keys as [$latKey, $lngKey]) {
            $lat = data_get($payload, $latKey);
            $lng = data_get($payload, $lngKey);
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            try {
                return $this->distanceCalculator->drivingDistance(
                    $origin['lat'],
                    $origin['lng'],
                    (float) $lat,
                    (float) $lng,
                );
            } catch (\Throwable $exception) {
                Log::warning('pricing.endpoint_ring_distance_failed', [
                    'type' => $type,
                    'branch_id' => $branch->id,
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    private function resolveDistance(array $payload): float
    {
        return (float) $this->resolveDistanceResult($payload)['distance_km'];
    }

    private function resolveDistanceResult(array $payload, ?Branch $pricingBranch = null, ?string $serviceType = null): array
    {
        if (isset($payload['distance'])) {
            return [
                'distance_km' => (float) $payload['distance'],
                'provider' => 'payload',
                'fallback_used' => false,
                'pricing_origin' => 'payload',
            ];
        }

        if (isset($payload['distance_km'])) {
            return [
                'distance_km' => (float) $payload['distance_km'],
                'provider' => 'payload',
                'fallback_used' => false,
                'pricing_origin' => 'payload',
            ];
        }

        $pickupLat = (float) $payload['pickup_lat'];
        $pickupLng = (float) $payload['pickup_lng'];
        $destinationLat = (float) $payload['destination_lat'];
        $destinationLng = (float) $payload['destination_lng'];
        $origin = $this->pricingDistanceOrigin($payload, $pricingBranch, $serviceType);

        if ($origin !== null) {
            try {
                return [
                    ...$this->distanceCalculator->drivingDistanceResult(
                        $origin['lat'],
                        $origin['lng'],
                        $destinationLat,
                        $destinationLng,
                    ),
                    'pricing_origin' => 'branch_pricing_origin',
                    'pricing_origin_branch_id' => $pricingBranch?->id,
                    'pricing_origin_name' => $origin['name'],
                    'pricing_origin_lat' => $origin['lat'],
                    'pricing_origin_lng' => $origin['lng'],
                    'pricing_origin_source' => $origin['source'],
                    'pickup_outside_pricing_branch' => false,
                ];
            } catch (\Throwable $exception) {
                Log::warning('pricing.origin_distance_failed', [
                    'branch_id' => $pricingBranch?->id,
                    'origin' => $origin['name'],
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            ...$this->distanceCalculator->drivingDistanceResult(
                $pickupLat,
                $pickupLng,
                $destinationLat,
                $destinationLng,
            ),
            'pricing_origin' => 'pickup',
            'pickup_outside_pricing_branch' => $pricingBranch !== null && $this->pickupIsOutsidePricingBranch($payload, $pricingBranch),
        ];
    }

    /**
     * @return array{lat: float, lng: float, name: string, source: string}|null
     */
    private function pricingDistanceOrigin(array $payload, ?Branch $pricingBranch, ?string $serviceType): ?array
    {
        if ($pricingBranch === null || in_array($serviceType, ['travel', 'joker_mobil'], true)) {
            return null;
        }

        if ($this->pickupIsOutsidePricingBranch($payload, $pricingBranch)) {
            return null;
        }

        return $pricingBranch->pricingOriginPoint();
    }

    private function pickupIsOutsidePricingBranch(array $payload, Branch $pricingBranch): bool
    {
        $lat = data_get($payload, 'pickup_lat') ?? data_get($payload, 'origin_lat') ?? data_get($payload, 'service_payload.pickup_lat');
        $lng = data_get($payload, 'pickup_lng') ?? data_get($payload, 'origin_lng') ?? data_get($payload, 'service_payload.pickup_lng');
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return false;
        }

        $detectedBranch = $this->branches->detect((float) $lat, (float) $lng)['branch'] ?? null;
        if (! $detectedBranch instanceof Branch) {
            return false;
        }

        return $this->branchRootId($detectedBranch) !== $this->branchRootId($pricingBranch);
    }

    private function branchRootId(Branch $branch): int
    {
        return (int) ($branch->parent_branch_id ?: $branch->id);
    }

    private function validate(string $serviceType, float $distance, int $stops): void
    {
        if ($distance < 0) {
            throw new InvalidArgumentException('Distance tidak boleh negatif.');
        }

        if ($stops < 1) {
            throw new InvalidArgumentException('Stops minimal 1.');
        }

        if (
            ! in_array($serviceType, self::GENERAL_SERVICES, true)
            && ! in_array($serviceType, ['travel', 'joker_mobil'], true)
        ) {
            throw new InvalidArgumentException('Service type tidak valid.');
        }
    }

    private function normalizeServiceType(string $serviceType): string
    {
        return match (strtolower(trim($serviceType))) {
            'do' => 'delivery',
            'gift order' => 'gift_order',
            'gift' => 'gift_order',
            'joker mobil', 'joker-mobile', 'joker' => 'joker_mobil',
            default => strtolower(trim($serviceType)),
        };
    }

    private function textFromMixed(array|string|null $text): string
    {
        if ($text === null) {
            return '';
        }

        if (is_string($text)) {
            return $text;
        }

        return collect($text)
            ->flatten()
            ->map(fn ($value): string => is_scalar($value) ? (string) $value : '')
            ->implode(' ');
    }
}
