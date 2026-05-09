<?php

namespace App\Services;

use App\Models\Order;
use App\Models\RingPricingRule;
use App\Models\RingPricingSuggestion;
use App\Models\User;
use Illuminate\Support\Str;

class RingPricingService
{
    public function match(array $payload, string $serviceType): ?RingPricingRule
    {
        $branchId = isset($payload['branch_id']) ? (int) $payload['branch_id'] : null;
        $pickupText = $this->normalize($payload['pickup_address'] ?? $payload['pickup_text'] ?? '');
        $destinationText = $this->normalize($payload['destination_address'] ?? $payload['destination_text'] ?? '');

        if ($pickupText === '' || $destinationText === '') {
            return null;
        }

        return RingPricingRule::query()
            ->with('branch')
            ->where('is_active', true)
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
