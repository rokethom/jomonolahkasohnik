<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCrewRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class OrderCrewDecisionService
{
    private const CACHE_KEY = 'order_crew_rules.active.v2';

    public function activeRules(): Collection
    {
        if (! Schema::hasTable('order_crew_rules')) {
            return collect();
        }

        return Cache::rememberForever(self::CACHE_KEY, fn (): Collection => OrderCrewRule::query()
            ->active()
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get());
    }

    public function clearCache(): bool
    {
        return Cache::forget(self::CACHE_KEY);
    }

    public function decide(array $payload): ?array
    {
        $serviceType = $this->normalizeServiceType((string) ($payload['service_type'] ?? ''));
        $haystack = $this->textFromPayload($payload);

        foreach ($this->activeRules() as $rule) {
            if (! $this->scopeMatches($serviceType, $rule->service_scopes)) {
                continue;
            }

            $keyword = $this->matchedKeyword($haystack, (string) $rule->keywords);
            if ($keyword === null) {
                continue;
            }

            return [
                'requires_helper' => (bool) $rule->requires_helper,
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'keyword' => $keyword,
                'crew_roles' => ['rider', $rule->helper_role ?: 'helper'],
                'helper_role' => $rule->helper_role ?: 'helper',
                'helper_label' => $rule->helper_label ?: 'Helper',
                'helper_service_charge' => (int) $rule->helper_service_charge,
                'helper_pricing' => [
                    'base_distance_km' => (float) ($rule->helper_base_distance_km ?? 10),
                    'base_price' => (int) ($rule->helper_base_price ?? 6000),
                    'over_distance_percent' => (float) ($rule->helper_over_distance_percent ?? 50),
                ],
            ];
        }

        return null;
    }

    public function applyHelperPricingToQuote(array $quote, array $decision): array
    {
        $helperFee = $this->helperCharge(
            (float) ($quote['distance'] ?? $quote['distance_km'] ?? 0),
            (int) ($quote['tarif'] ?? $quote['price'] ?? 0),
            $decision,
        );

        $decision['helper_service_charge'] = $helperFee;
        $decision['helper_fee'] = $helperFee;
        $quote['crew_decision'] = $decision;
        $quote['crew_helper_fee'] = $helperFee;
        $quote['helper_service_charge'] = $helperFee;
        $quote['total_before_round'] = (int) ($quote['total_before_round'] ?? $quote['subtotal'] ?? $quote['final_price'] ?? 0) + $helperFee;
        $quote['subtotal'] = $quote['total_before_round'];
        $quote['final_price'] = (int) $quote['total_before_round'];
        $quote['total_price'] = $quote['final_price'];

        return $quote;
    }

    public function helperChargeForOrder(Order $order, ?array $decision = null): int
    {
        $decision ??= data_get($order->pricing_breakdown, 'crew_decision');
        if (! is_array($decision)) {
            return 0;
        }

        return $this->helperCharge(
            (float) $order->distance_km,
            (int) $order->price,
            $decision,
        );
    }

    public function createPendingHelperCrew(Order $order): void
    {
        $decision = data_get($order->pricing_breakdown, 'crew_decision');

        if (! is_array($decision) || ! ($decision['requires_helper'] ?? false)) {
            return;
        }

        $order->crews()->updateOrCreate(
            ['role' => 'rider'],
            [
                'driver_id' => $order->driver_id,
                'order_crew_rule_id' => $decision['rule_id'] ?? null,
                'label' => 'Rider',
                'status' => 'accepted',
                'service_charge' => (int) $order->price,
                'accepted_at' => now(),
            ],
        );

        $helperCharge = $this->helperChargeForOrder($order, $decision);

        $order->crews()->firstOrCreate(
            ['role' => (string) ($decision['helper_role'] ?? 'helper')],
            [
                'order_crew_rule_id' => $decision['rule_id'] ?? null,
                'label' => (string) ($decision['helper_label'] ?? 'Helper'),
                'status' => 'pending',
                'service_charge' => $helperCharge,
            ],
        );
    }

    private function helperCharge(float $distanceKm, int $driverPrice, array $decision): int
    {
        $pricing = is_array($decision['helper_pricing'] ?? null) ? $decision['helper_pricing'] : [];
        $baseDistance = max(0, (float) ($pricing['base_distance_km'] ?? 10));
        $basePrice = max(0, (int) ($pricing['base_price'] ?? ($decision['helper_service_charge'] ?? 6000)));
        $percent = max(0, (float) ($pricing['over_distance_percent'] ?? 50));

        if ($distanceKm <= $baseDistance) {
            return $basePrice;
        }

        return max(0, (int) ceil($driverPrice * ($percent / 100)));
    }

    private function scopeMatches(string $serviceType, ?array $scopes): bool
    {
        $scopes = collect($scopes ?: ['all'])
            ->map(fn (mixed $scope): string => $this->normalizeServiceType((string) $scope))
            ->filter()
            ->values()
            ->all();

        return $scopes === [] || in_array('all', $scopes, true) || in_array($serviceType, $scopes, true);
    }

    private function matchedKeyword(string $haystack, string $keywords): ?string
    {
        foreach (explode(',', mb_strtolower($keywords)) as $keyword) {
            $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? '');
            if ($keyword === '') {
                continue;
            }

            if (preg_match('/(?:^|[^\pL\pN])'.preg_quote($keyword, '/').'(?:[^\pL\pN]|$)/u', $haystack) === 1) {
                return $keyword;
            }
        }

        return null;
    }

    private function textFromPayload(array $payload): string
    {
        return mb_strtolower(collect([
            $payload['service_type'] ?? '',
            $payload['pickup_address'] ?? '',
            $payload['destination_address'] ?? '',
            $payload['notes'] ?? '',
            json_encode($payload['service_payload'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($payload['items'] ?? [], JSON_UNESCAPED_UNICODE),
        ])->filter()->implode(' '));
    }

    private function normalizeServiceType(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'do' => 'delivery',
            'go' => 'gift_order',
            'oj' => 'ojek',
            'kr' => 'kurir',
            'bl' => 'belanja',
            'jm' => 'joker_mobil',
            default => $value,
        };
    }
}
