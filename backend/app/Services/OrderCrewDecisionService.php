<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCrewRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class OrderCrewDecisionService
{
    private const CACHE_KEY = 'order_crew_rules.active';

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
            ];
        }

        return null;
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
                'service_charge' => (int) $order->service_charge,
                'accepted_at' => now(),
            ],
        );

        $order->crews()->firstOrCreate(
            ['role' => (string) ($decision['helper_role'] ?? 'helper')],
            [
                'order_crew_rule_id' => $decision['rule_id'] ?? null,
                'label' => (string) ($decision['helper_label'] ?? 'Helper'),
                'status' => 'pending',
                'service_charge' => (int) ($decision['helper_service_charge'] ?? 0),
            ],
        );
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
