<?php

namespace App\Services;

use App\Models\GeofenceArea;
use App\Models\ZonePricingRule;
use Illuminate\Support\Facades\Schema;

class ZonePricingService
{
    public function __construct(private readonly GeofenceService $geofence)
    {
    }

    public function match(array $payload, string $serviceType, float $distanceKm): ?ZonePricingRule
    {
        if (! Schema::hasTable('zone_pricing_rules')) {
            return null;
        }

        $branchIds = $this->candidateBranchIds($payload);

        return ZonePricingRule::query()
            ->with(['branch', 'geofenceArea.branch'])
            ->active()
            ->where(function ($query) use ($branchIds): void {
                $query->whereNull('branch_id');

                if ($branchIds !== []) {
                    $query->orWhereIn('branch_id', $branchIds);
                }
            })
            ->forDistance($distanceKm)
            ->where(function ($query) use ($serviceType): void {
                $query->whereNull('service_type')
                    ->orWhere('service_type', $serviceType);
            })
            ->orderByRaw('branch_id IS NULL')
            ->orderByDesc('priority')
            ->latest()
            ->get()
            ->first(fn (ZonePricingRule $rule): bool => $this->ruleMatchesPoint($rule, $payload));
    }

    public function apply(array $quote, ZonePricingRule $rule): array
    {
        $tarif = (int) ($quote['tarif'] ?? $quote['price'] ?? 0);
        $mode = $rule->price_mode;
        $adjustment = 0;

        if ($mode === 'fixed') {
            $tarif = (int) $rule->amount;
        } elseif ($mode === 'extra') {
            $adjustment = (int) $rule->amount;
            $tarif += $adjustment;
        } elseif ($mode === 'percent') {
            $adjustment = (int) ceil($tarif * ((float) $rule->percent / 100));
            $tarif += $adjustment;
        }

        $serviceCharge = (int) ($quote['service_charge'] ?? $quote['service_fee'] ?? 0);
        $extraCharge = (int) ($quote['extra_charge'] ?? 0);
        $totalBeforeRound = $tarif + $serviceCharge + $extraCharge;
        $finalPrice = (int) (ceil($totalBeforeRound / 1000) * 1000);

        return [
            ...$quote,
            'zone_pricing_rule_id' => $rule->id,
            'zone_pricing_rule_name' => $rule->name,
            'zone_pricing_area_id' => $rule->geofence_area_id,
            'zone_pricing_area_name' => $rule->geofenceArea?->name,
            'zone_pricing_mode' => $mode,
            'zone_pricing_adjustment' => $mode === 'fixed' ? 0 : $adjustment,
            'tarif_source' => 'zone_pricing',
            'tarif' => $tarif,
            'price' => $tarif,
            'base_price' => $tarif,
            'total_before_round' => $totalBeforeRound,
            'subtotal' => $totalBeforeRound,
            'final_price' => $finalPrice,
            'total_price' => $finalPrice,
        ];
    }

    public function testPoint(float $lat, float $lng, ?int $branchId = null): ?array
    {
        $area = GeofenceArea::query()
            ->with('branch')
            ->where('is_active', true)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderByDesc('priority')
            ->orderBy('radius_meters')
            ->get()
            ->first(fn (GeofenceArea $area): bool => $this->geofence->containsPoint($lat, $lng, $area));

        if (! $area) {
            return null;
        }

        return [
            'area' => $area,
            'branch' => $area->branch,
            'distance_meters' => $this->geofence->distanceToAreaCenter($lat, $lng, $area),
        ];
    }

    private function ruleMatchesPoint(ZonePricingRule $rule, array $payload): bool
    {
        $area = $rule->geofenceArea;
        if (! $area || ! $area->is_active) {
            return false;
        }

        $pickupMatches = $this->payloadPointMatches($payload, 'pickup_lat', 'pickup_lng', $area);
        $destinationMatches = $this->payloadPointMatches($payload, 'destination_lat', 'destination_lng', $area);

        return match ($rule->match_point) {
            'pickup' => $pickupMatches,
            'either' => $pickupMatches || $destinationMatches,
            'both' => $pickupMatches && $destinationMatches,
            default => $destinationMatches,
        };
    }

    private function payloadPointMatches(array $payload, string $latKey, string $lngKey, GeofenceArea $area): bool
    {
        if (! isset($payload[$latKey], $payload[$lngKey])) {
            return false;
        }

        return $this->geofence->containsPoint((float) $payload[$latKey], (float) $payload[$lngKey], $area);
    }

    /**
     * @return array<int, int>
     */
    private function candidateBranchIds(array $payload): array
    {
        $branchIds = [];

        if (isset($payload['branch_id']) && (int) $payload['branch_id'] > 0) {
            $branchIds[] = (int) $payload['branch_id'];
        }

        foreach ([['destination_lat', 'destination_lng'], ['pickup_lat', 'pickup_lng']] as [$latKey, $lngKey]) {
            if (! isset($payload[$latKey], $payload[$lngKey])) {
                continue;
            }

            $match = $this->testPoint((float) $payload[$latKey], (float) $payload[$lngKey]);
            $branchId = data_get($match, 'branch.id');
            if ($branchId) {
                $branchIds[] = (int) $branchId;
            }
        }

        return array_values(array_unique($branchIds));
    }
}
