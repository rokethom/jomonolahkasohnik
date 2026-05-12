<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RingPricingRule;
use App\Models\RingPricingSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RingPricingService
{
    public function match(array $payload, string $serviceType): ?RingPricingRule
    {
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $pickupText = $this->normalize($this->pickupText($payload));
        $destinationText = $this->normalize($this->destinationText($payload));
        $pickupPoint = $this->pointFromPayload($payload, 'pickup');
        $destinationPoint = $this->pointFromPayload($payload, 'destination');

        $hasPolygonColumns = Schema::hasColumn('ring_pricing_rules', 'area_mode')
            && Schema::hasColumn('ring_pricing_rules', 'polygon_coordinates');

        if ($hasPolygonColumns && $branchId !== null && ($pickupPoint !== null || $destinationPoint !== null)) {
            $polygonRule = RingPricingRule::query()
                ->with('branch')
                ->where('is_active', true)
                ->where('branch_id', $branchId)
                ->where('area_mode', 'polygon')
                ->forService($serviceType)
                ->orderByRaw('service_type IS NULL')
                ->latest()
                ->get()
                ->first(fn (RingPricingRule $rule): bool => $this->matchesPolygon($rule, $pickupPoint, $destinationPoint));

            if ($polygonRule) {
                return $polygonRule;
            }
        }

        if ($pickupText === '' || $destinationText === '') {
            return null;
        }

        return RingPricingRule::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($hasPolygonColumns, fn ($query) => $query->where(fn ($query) => $query->whereNull('area_mode')->orWhere('area_mode', 'text')))
            ->forBranch($branchId)
            ->forService($serviceType)
            ->orderByRaw('branch_id IS NULL')
            ->orderByRaw('service_type IS NULL')
            ->latest()
            ->get()
            ->first(fn (RingPricingRule $rule): bool => $this->matches($rule, $pickupText, $destinationText));
    }

    public function apply(array $quote, RingPricingRule $rule): array
    {
        $serviceCharge = (int) ($quote['service_charge'] ?? $quote['service_fee'] ?? 0);
        $totalBeforeRound = (int) $rule->price + $serviceCharge;
        $finalPrice = (int) (ceil($totalBeforeRound / 1000) * 1000);

        return [
            ...$quote,
            'ring_pricing_rule_id' => $rule->id,
            'ring_pricing_source' => $rule->source,
            'ring' => $rule->ring,
            'ring_route' => [
                'pickup_area' => $rule->pickup_area,
                'destination_area' => $rule->destination_area,
                'branch' => $rule->branch?->name,
                'area_mode' => $rule->area_mode ?? 'text',
                'polygon_match_point' => $rule->polygon_match_point,
            ],
            'tarif' => (int) $rule->price,
            'price' => (int) $rule->price,
            'base_price' => (int) $rule->price,
            'total_before_round' => $totalBeforeRound,
            'subtotal' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'total_price' => $finalPrice,
        ];
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
