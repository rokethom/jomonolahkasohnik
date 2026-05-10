<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\PriceSetting;
use App\Services\Pricing\DistanceCalculator;
use App\Services\Pricing\JokerPricing;
use App\Services\Pricing\ServiceFeeCalculator;
use App\Services\Pricing\TravelPricing;
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
    private const DEFAULT_PER_KM_RATE = 4000;

    public function __construct(
        private readonly DistanceCalculator $distanceCalculator,
        private readonly ServiceFeeCalculator $serviceFeeCalculator,
        private readonly JokerPricing $jokerPricing,
        private readonly TravelPricing $travelPricing,
        private readonly PricingKeywordRuleService $keywordRules,
        private readonly OrderOperationService $operations,
        private readonly RingPricingService $ringPricing,
        private readonly ZonePricingService $zonePricing,
        private readonly OrderCrewDecisionService $crewDecisions,
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
        return $this->distanceCalculator->haversine($lat1, $lng1, $lat2, $lng2);
    }

    public function distanceInKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return $this->calculateDistance($lat1, $lng1, $lat2, $lng2);
    }

    public function calculateTarifFromDatabase(float $distanceKm, ?int $branchId = null): int
    {
        $setting = PriceSetting::query()
            ->forBranch($branchId)
            ->forDistance($distanceKm)
            ->orderByRaw('branch_id IS NULL')
            ->orderByDesc('min_km')
            ->first();

        if (! $setting) {
            $minimumFlat = PriceSetting::query()
                ->forBranch($branchId)
                ->whereNotNull('price')
                ->orderByRaw('branch_id IS NULL')
                ->orderBy('min_km')
                ->first();

            if ($minimumFlat && $distanceKm < (float) $minimumFlat->min_km) {
                return (int) $minimumFlat->price;
            }

            $setting = PriceSetting::query()
                ->forBranch($branchId)
                ->whereNotNull('per_km_rate')
                ->orderByRaw('branch_id IS NULL')
                ->orderByDesc('max_km')
                ->orderByDesc('min_km')
                ->first();
        }

        if (! $setting) {
            return $this->roundUpPrice((int) ceil($distanceKm * self::DEFAULT_PER_KM_RATE));
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
        $distance = $this->resolveDistance($payload);
        $route = $payload['route'] ?? $payload['travel_route'] ?? $payload['service_payload']['route'] ?? null;

        $quote = $this->calculateByDistance($serviceType, $distance, $stops, $route);
        if ($this->shouldUseDatabaseTarif($serviceType, $distance, isset($payload['branch_id']) ? (int) $payload['branch_id'] : null)) {
            $quote = $this->replaceTarif(
                $quote,
                $this->calculateTarifFromDatabase($distance, isset($payload['branch_id']) ? (int) $payload['branch_id'] : null),
            );
        }
        if ($ringRule = $this->ringPricing->match($payload, $serviceType)) {
            $quote = $this->ringPricing->apply($quote, $ringRule);
        }
        if ($zoneRule = $this->zonePricing->match($payload, $serviceType, $distance)) {
            $quote = $this->zonePricing->apply($quote, $zoneRule);
        }
        $extraCharge = $this->extraServiceChargeForService($serviceType, [
            $payload['pickup_address'] ?? '',
            $payload['store_location'] ?? '',
            $payload['purchase_address'] ?? '',
            $payload['destination_text'] ?? $payload['destination_address'] ?? '',
            $payload['notes'] ?? '',
            $payload['service_payload'] ?? [],
            $payload['items'] ?? [],
        ]);

        if ($extraCharge > 0 && $serviceType !== 'travel') {
            $quote['extra_charge'] = $extraCharge;
            $quote['keyword_charge'] = $extraCharge;
            $quote['service_charge'] += $extraCharge;
            $quote['total_before_round'] += $extraCharge;
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
            $quote['base_tarif_before_night'] = $baseTarifBeforeNight;
            $quote['night_tariff_charge'] = (int) $night['amount'];
            $quote['night_tariff_percent'] = (int) $night['percent'];
            $quote['tarif'] += (int) $night['amount'];
            $quote['price'] = $quote['tarif'];
            $quote['base_price'] = $quote['tarif'];
            $quote['total_before_round'] += (int) $night['amount'];
            $quote['subtotal'] = $quote['total_before_round'];
            $quote['final_price'] = $this->roundUpPrice($quote['total_before_round']);
            $quote['total_price'] = $quote['final_price'];
        }

        if ($crewDecision = $this->crewDecisions->decide($payload)) {
            $quote = $this->crewDecisions->applyHelperPricingToQuote($quote, $crewDecision);
        }

        return $quote;
    }

    private function calculateGeneral(string $serviceType, float $distance, int $stops): array
    {
        $tarif = $this->generalDistanceTarif($distance);
        $serviceCharge = $this->calculateServiceFee($stops);
        $totalBeforeRound = $tarif + $serviceCharge;
        $finalPrice = $this->roundUpPrice($totalBeforeRound);

        return $this->response([
            'service_type' => $serviceType,
            'distance' => $distance,
            'tarif' => $tarif,
            'service_charge' => $serviceCharge,
            'total_before_round' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'stops' => $stops,
            'service_fee_breakdown' => $this->serviceFeeBreakdown($stops),
        ]);
    }

    private function shouldUseDatabaseTarif(string $serviceType, float $distance, ?int $branchId): bool
    {
        if ($distance <= 0) {
            return false;
        }

        if (in_array($serviceType, ['travel', 'joker_mobil'], true)) {
            return false;
        }

        return PriceSetting::query()
            ->forBranch($branchId)
            ->forDistance($distance)
            ->exists()
            || PriceSetting::query()
                ->forBranch($branchId)
                ->whereNotNull('per_km_rate')
                ->exists();
    }

    private function replaceTarif(array $quote, int $tarif): array
    {
        $serviceCharge = (int) ($quote['service_charge'] ?? $quote['service_fee'] ?? 0);
        $totalBeforeRound = $tarif + $serviceCharge;
        $finalPrice = $this->roundUpPrice($totalBeforeRound);

        return [
            ...$quote,
            'tarif_source' => 'price_settings',
            'tarif' => $tarif,
            'price' => $tarif,
            'base_price' => $tarif,
            'total_before_round' => $totalBeforeRound,
            'subtotal' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'total_price' => $finalPrice,
        ];
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

    private function generalDistanceTarif(float $distance): int
    {
        if ($distance <= 5) {
            return 6000;
        }

        if ($distance <= 10) {
            return 12000;
        }

        return max(0, (int) ceil(($distance * 1900) - 7000));
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

    private function resolveDistance(array $payload): float
    {
        if (isset($payload['distance'])) {
            return (float) $payload['distance'];
        }

        if (isset($payload['distance_km'])) {
            return (float) $payload['distance_km'];
        }

        return $this->distanceWithOsrmFallback(
            (float) $payload['pickup_lat'],
            (float) $payload['pickup_lng'],
            (float) $payload['destination_lat'],
            (float) $payload['destination_lng'],
        );
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
