<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\DTOs\Pricing\PricingRequestData;
use App\DTOs\Pricing\PricingResultData;
use App\DTOs\Pricing\SpatialDetectionData;
use App\Services\Distance\DistanceService;
use App\Services\Formula\PricingFormulaService;

class PricingCalculationService
{
    public function __construct(
        private readonly DistanceService $distance,
        private readonly PricingRuleService $rules,
        private readonly PricingFormulaService $formula,
    ) {
    }

    public function calculate(PricingRequestData $request, SpatialDetectionData $spatial, array $workflow = []): PricingResultData
    {
        $distanceKm = $this->distance->calculateDistanceKm(
            $request->pickupLatitude,
            $request->pickupLongitude,
            $request->destinationLatitude,
            $request->destinationLongitude,
        );
        $ring = $this->rules->resolveRing($distanceKm);
        $calculated = $this->formula->calculate($ring, $distanceKm);

        return new PricingResultData(
            request: $request,
            distanceKm: $distanceKm,
            branch: $spatial->branch,
            area: $spatial->area,
            pricingRing: $ring,
            price: (int) $calculated['price'],
            formula: (string) $calculated['formula'],
            workflow: $workflow,
        );
    }
}
