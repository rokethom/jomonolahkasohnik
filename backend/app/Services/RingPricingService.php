<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RingPricingRule;
use App\Models\RingPricingSuggestion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RingPricingService
{
    public function matchMasterPolygon(array $payload, string $serviceType, ?int $branchId, ?float $distanceFromBranchKm): ?array
    {
        return $this->matchMasterDistance($payload, $serviceType, $branchId, $distanceFromBranchKm);
    }

    public function matchMasterDistance(array $payload, string $serviceType, ?int $branchId, ?float $distanceKm): ?array
    {
        if ($distanceKm === null) {
            return null;
        }

        $pickupPoint = $this->pointFromPayload($payload, 'pickup');
        $destinationPoint = $this->pointFromPayload($payload, 'destination');

        $rules = $this->masterDistanceRules($branchId, $serviceType);
        if ($rules->isEmpty()) {
            return null;
        }

        $selectedRule = $rules
            ->filter(fn (RingPricingRule $rule): bool => $this->matchesDistanceRange($rule, $distanceKm))
            ->sort($this->compareRules(...))
            ->first();
        if (! $selectedRule) {
            return null;
        }

        return [
            'rule' => $selectedRule,
            'distance_from_branch_km' => round($distanceKm, 2),
            'pickup_ring' => null,
            'destination_ring' => $selectedRule->ring,
            'is_cross_ring' => false,
            'branch_id' => $branchId,
        ];
    }

    public function match(array $payload, string $serviceType): ?RingPricingRule
    {
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $pickupText = $this->normalize($this->pickupText($payload));
        $destinationText = $this->normalize($this->destinationText($payload));
        $pickupPoint = $this->pointFromPayload($payload, 'pickup');
        $destinationPoint = $this->pointFromPayload($payload, 'destination');

        $hasPolygonColumns = Schema::hasColumn('ring_pricing_rules', 'area_mode')
            && Schema::hasColumn('ring_pricing_rules', 'polygon_coordinates');

        if ($pickupText === '' || $destinationText === '') {
            return null;
        }

        $rules = RingPricingRule::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($hasPolygonColumns, fn ($query) => $query->where(fn ($query) => $query->whereNull('area_mode')->orWhere('area_mode', 'text')))
            ->forBranch($branchId)
            ->forService($serviceType)
            ->latest()
            ->get();

        return $this->bestMatchingRule(
            $rules,
            fn (RingPricingRule $rule): bool => $this->matches($rule, $pickupText, $destinationText),
        );
    }

    public function apply(array $quote, RingPricingRule $rule): array
    {
        return $this->applyMaster($quote, $rule, null);
    }

    public function applyMaster(array $quote, RingPricingRule $rule, ?array $meta = null): array
    {
        $distance = (float) ($meta['distance_from_branch_km'] ?? $quote['distance_from_branch_km'] ?? $quote['distance'] ?? 0);
        $tarif = $this->calculateRuleTarif($rule, $distance);
        $serviceCharge = (int) ($rule->service_fee ?? ($quote['service_charge'] ?? $quote['service_fee'] ?? 0));
        $totalBeforeRound = $tarif + $serviceCharge;
        $finalPrice = (int) (ceil($totalBeforeRound / 1000) * 1000);

        return [
            ...$quote,
            'ring_pricing_rule_id' => $rule->id,
            'ring_pricing_source' => $rule->source,
            'ring' => $rule->ring,
            'distance_from_branch_km' => $distance,
            'pricing_mode' => $rule->pricing_mode ?? 'flat',
            'ring_priority' => (int) ($rule->priority ?: $this->ringPriority($rule->ring)),
            'pickup_ring' => $meta['pickup_ring'] ?? null,
            'destination_ring' => $meta['destination_ring'] ?? $rule->ring,
            'is_cross_ring' => (bool) ($meta['is_cross_ring'] ?? false),
            'cross_ring' => ($meta['pickup_ring'] ?? null) && ($meta['destination_ring'] ?? null) && ($meta['pickup_ring'] !== $meta['destination_ring'])
                ? ($meta['pickup_ring'].'_to_'.$meta['destination_ring'])
                : null,
            'ring_route' => [
                'pickup_area' => $rule->pickup_area,
                'destination_area' => $rule->destination_area,
                'branch' => $rule->branch?->name,
                'area_mode' => $rule->area_mode ?? 'text',
                'polygon_match_point' => $rule->polygon_match_point,
                'match_type' => $rule->match_type ?? 'point',
                'pickup_ring' => $meta['pickup_ring'] ?? null,
                'destination_ring' => $meta['destination_ring'] ?? null,
            ],
            'tarif' => $tarif,
            'price' => $tarif,
            'base_price' => $tarif,
            'service_charge' => $serviceCharge,
            'service_fee' => $serviceCharge,
            'total_before_round' => $totalBeforeRound,
            'subtotal' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'total_price' => $finalPrice,
        ];
    }

    public function calculateRuleTarif(RingPricingRule $rule, float $distanceKm): int
    {
        if (($rule->pricing_mode ?? 'flat') !== 'formula') {
            return (int) $rule->price;
        }

        $price = ($distanceKm * (int) ($rule->per_km_rate ?? 0)) - (int) ($rule->subtract_value ?? 0);

        return max(0, (int) ceil($price));
    }

    public function recordPriceEdit(Order $order, User $actor, int $previousPrice, int $newPrice): ?RingPricingSuggestion
    {
        if ($previousPrice === $newPrice || ! $order->pickup_address || ! $order->destination_address) {
            return null;
        }

        $pickupArea = $this->areaName($order->pickup_address);
        $destinationArea = $this->areaName($order->destination_address);
        $serviceType = $this->normalizeServiceType((string) ($order->service_type ?? $order->service_code ?? ''));
        $ring = $this->ringFromPrice($newPrice);

        $suggestion = RingPricingSuggestion::query()
            ->where('status', 'pending')
            ->where('branch_id', $order->branch_id)
            ->where('service_type', $serviceType ?: null)
            ->where('pickup_area', $pickupArea)
            ->where('destination_area', $destinationArea)
            ->where('suggested_price', $newPrice)
            ->first();

        $samples = array_values(array_unique(array_filter([
            ...($suggestion?->sample_order_ids ?? []),
            $order->id,
        ])));

        $payload = [
            'branch_id' => $order->branch_id,
            'service_type' => $serviceType ?: null,
            'pickup_area' => $pickupArea,
            'destination_area' => $destinationArea,
            'ring' => $ring,
            'suggested_price' => $newPrice,
            'previous_price' => $previousPrice,
            'sample_order_ids' => array_slice($samples, -10),
            'last_order_id' => $order->id,
            'last_edited_by' => $actor->id,
        ];

        if ($suggestion) {
            $suggestion->forceFill([
                ...$payload,
                'occurrence_count' => $suggestion->occurrence_count + 1,
            ])->save();

            return $suggestion->fresh(['branch', 'lastOrder', 'editor']);
        }

        return RingPricingSuggestion::create($payload)->load(['branch', 'lastOrder', 'editor']);
    }

    public function normalizeServiceType(string $serviceType): string
    {
        return match (strtolower(trim($serviceType))) {
            'do' => 'delivery',
            'gift order', 'gift' => 'gift_order',
            'joker mobil', 'joker-mobile', 'joker' => 'joker_mobil',
            default => strtolower(trim($serviceType)),
        };
    }

    /**
     * @return Collection<int, RingPricingRule>
     */
    private function masterPolygonRules(int $branchId, string $serviceType): Collection
    {
        return collect();
    }

    /**
     * @return Collection<int, RingPricingRule>
     */
    private function masterDistanceRules(?int $branchId, string $serviceType): Collection
    {
        if (! Schema::hasColumn('ring_pricing_rules', 'min_km')
            || ! Schema::hasColumn('ring_pricing_rules', 'priority')) {
            return collect();
        }

        return RingPricingRule::query()
            ->with('branch')
            ->where('is_active', true)
            ->forBranch($branchId)
            ->forService($serviceType)
            ->when(
                Schema::hasColumn('ring_pricing_rules', 'area_mode'),
                fn ($query) => $query->where(fn ($query) => $query->whereNull('area_mode')->orWhere('area_mode', 'text')),
            )
            ->where('pickup_area', '*')
            ->where('destination_area', '*')
            ->orderByDesc('priority')
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @param  Collection<int, RingPricingRule>  $rules
     * @param  array{lat: float, lng: float}  $point
     */
    private function bestMasterRuleForPoint(Collection $rules, array $point, float $distanceKm): ?RingPricingRule
    {
        return $rules
            ->filter(fn (RingPricingRule $rule): bool => $this->matchesDistanceRange($rule, $distanceKm)
                && $this->matchesMasterPolygonPoint($rule, $point))
            ->sort($this->compareRules(...))
            ->first();
    }

    /**
     * @param  array{lat: float, lng: float}  $point
     */
    private function matchesMasterPolygonPoint(RingPricingRule $rule, array $point): bool
    {
        $polygon = $this->polygonPoints($rule->polygon_coordinates ?? []);

        return count($polygon) >= 3 && $this->pointInPolygon($point['lat'], $point['lng'], $polygon);
    }

    private function matchesDistanceRange(RingPricingRule $rule, float $distanceKm): bool
    {
        $min = (float) ($rule->min_km ?? 0);
        $max = $rule->max_km !== null ? (float) $rule->max_km : null;

        return $distanceKm >= $min && ($max === null || $distanceKm <= $max);
    }

    private function matches(RingPricingRule $rule, string $pickupText, string $destinationText): bool
    {
        $pickupTerms = $this->terms($rule->pickup_area, $rule->pickup_aliases ?? []);
        $destinationTerms = $this->terms($rule->destination_area, $rule->destination_aliases ?? []);

        $forward = $this->containsAny($pickupText, $pickupTerms) && $this->containsAny($destinationText, $destinationTerms);
        if ($forward || ! $rule->is_bidirectional) {
            return $forward;
        }

        return $this->containsAny($pickupText, $destinationTerms) && $this->containsAny($destinationText, $pickupTerms);
    }

    private function bestMatchingRule(iterable $rules, callable $matches): ?RingPricingRule
    {
        return collect($rules)
            ->filter(fn (RingPricingRule $rule): bool => $matches($rule))
            ->sort($this->compareRules(...))
            ->first();
    }

    private function compareRules(RingPricingRule $left, RingPricingRule $right): int
    {
        $leftValues = $this->rulePriorityValues($left);
        $rightValues = $this->rulePriorityValues($right);

        foreach ($leftValues as $index => $leftValue) {
            $rightValue = $rightValues[$index] ?? 0;

            if ($leftValue === $rightValue) {
                continue;
            }

            return $rightValue <=> $leftValue;
        }

        return 0;
    }

    /**
     * @return array<int, int>
     */
    private function rulePriorityValues(RingPricingRule $rule): array
    {
        return [
            (int) ($rule->priority ?: $this->ringPriority($rule->ring)),
            $rule->branch_id !== null ? 1 : 0,
            filled($rule->service_type) ? 1 : 0,
            $rule->updated_at?->getTimestamp() ?? 0,
            (int) $rule->id,
        ];
    }

    private function ringPriority(?string $ring): int
    {
        return match ($ring) {
            'ring_1' => 300,
            'ring_2' => 200,
            'ring_3' => 100,
            default => 0,
        };
    }

    private function matchesPolygon(RingPricingRule $rule, ?array $pickupPoint, ?array $destinationPoint): bool
    {
        $polygon = $this->polygonPoints($rule->polygon_coordinates ?? []);
        if (count($polygon) < 3) {
            return false;
        }

        $pickupInside = $pickupPoint !== null && $this->pointInPolygon($pickupPoint['lat'], $pickupPoint['lng'], $polygon);
        $destinationInside = $destinationPoint !== null && $this->pointInPolygon($destinationPoint['lat'], $destinationPoint['lng'], $polygon);

        return match ($rule->polygon_match_point ?: 'destination_then_pickup') {
            'pickup' => $pickupInside,
            'either' => $pickupInside || $destinationInside,
            'both' => $pickupInside && $destinationInside,
            'destination_then_pickup' => $destinationPoint !== null ? $destinationInside : $pickupInside,
            default => $destinationInside,
        };
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function pointFromPayload(array $payload, string $type): ?array
    {
        $prefixes = match ($type) {
            'pickup' => [
                ['pickup_lat', 'pickup_lng'],
                ['origin_lat', 'origin_lng'],
                ['service_payload.pickup_lat', 'service_payload.pickup_lng'],
                ['service_payload.origin_lat', 'service_payload.origin_lng'],
            ],
            default => [
                ['destination_lat', 'destination_lng'],
                ['dropoff_lat', 'dropoff_lng'],
                ['service_payload.destination_lat', 'service_payload.destination_lng'],
                ['service_payload.dropoff_lat', 'service_payload.dropoff_lng'],
            ],
        };

        foreach ($prefixes as [$latKey, $lngKey]) {
            $lat = data_get($payload, $latKey);
            $lng = data_get($payload, $lngKey);

            if (is_numeric($lat) && is_numeric($lng)) {
                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }

        $points = data_get($payload, 'points', data_get($payload, 'service_payload.points', []));
        if (is_array($points) && $points !== []) {
            $point = $type === 'pickup' ? ($points[0] ?? null) : end($points);
            if (is_array($point)) {
                $lat = $point['lat'] ?? $point['latitude'] ?? null;
                $lng = $point['lng'] ?? $point['longitude'] ?? null;

                if (is_numeric($lat) && is_numeric($lng)) {
                    return ['lat' => (float) $lat, 'lng' => (float) $lng];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function polygonPoints(mixed $value): array
    {
        $points = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($points)) {
            return [];
        }

        return collect($points)
            ->map(function (mixed $point): ?array {
                if (! is_array($point)) {
                    return null;
                }

                $lat = $point['lat'] ?? $point['latitude'] ?? null;
                $lng = $point['lng'] ?? $point['longitude'] ?? null;

                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    return null;
                }

                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array{lat: float, lng: float}> $polygon
     */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xI = $polygon[$i]['lng'];
            $yI = $polygon[$i]['lat'];
            $xJ = $polygon[$j]['lng'];
            $yJ = $polygon[$j]['lat'];

            $intersects = (($yI > $lat) !== ($yJ > $lat))
                && ($lng < (($xJ - $xI) * ($lat - $yI) / (($yJ - $yI) ?: 1.0)) + $xI);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function pickupText(array $payload): string
    {
        return collect([
            $payload['pickup_address'] ?? null,
            $payload['pickup_text'] ?? null,
            $payload['origin_address'] ?? null,
            $payload['service_payload']['pickup_address'] ?? null,
            $payload['service_payload']['pickup'] ?? null,
            $payload['service_payload']['origin'] ?? null,
            $payload['service_payload']['purchase_location'] ?? null,
            $payload['service_payload']['lokasi_pembelian'] ?? null,
            $payload['service_payload']['alamat_pembelian'] ?? null,
            $this->pointsText($payload, 0),
        ])->filter()->implode(' ');
    }

    private function destinationText(array $payload): string
    {
        return collect([
            $payload['destination_address'] ?? null,
            $payload['destination_text'] ?? null,
            $payload['dropoff_address'] ?? null,
            $payload['service_payload']['destination_address'] ?? null,
            $payload['service_payload']['destination'] ?? null,
            $payload['service_payload']['dropoff'] ?? null,
            $payload['service_payload']['alamat_antar'] ?? null,
            $payload['service_payload']['tujuan'] ?? null,
            $this->pointsText($payload, -1),
        ])->filter()->implode(' ');
    }

    private function pointsText(array $payload, int $index): string
    {
        $points = $payload['points'] ?? $payload['service_payload']['points'] ?? [];
        if (! is_array($points) || $points === []) {
            return '';
        }

        $point = $index < 0 ? end($points) : ($points[$index] ?? null);
        if (is_array($point)) {
            return implode(' ', array_filter([
                $point['label'] ?? null,
                $point['address'] ?? null,
                $point['name'] ?? null,
            ]));
        }

        return is_string($point) ? $point : '';
    }

    private function terms(string $area, array $aliases): array
    {
        return collect([$area, ...$aliases])
            ->map(fn ($value): string => $this->normalize($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }

    private function areaName(string $address): string
    {
        $parts = preg_split('/[,\\-]+/', $address) ?: [];
        $candidate = trim((string) ($parts[0] ?? $address));

        return Str::limit($candidate !== '' ? $candidate : $address, 120, '');
    }

    private function ringFromPrice(int $price): ?string
    {
        return match (true) {
            $price > 0 && $price <= 7000 => 'ring_1',
            $price <= 12000 => 'ring_2',
            default => null,
        };
    }

    private function normalize(mixed $value): string
    {
        $text = Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();

        return $text;
    }
}
