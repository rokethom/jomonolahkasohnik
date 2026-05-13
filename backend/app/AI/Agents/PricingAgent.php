<?php

declare(strict_types=1);

namespace App\AI\Agents;

use App\DTOs\Pricing\PricingRequestData;
use App\DTOs\Pricing\PricingResultData;
use App\DTOs\Pricing\SpatialDetectionData;
use App\Services\Pricing\PricingCalculationService;

class PricingAgent
{
    public function __construct(private readonly PricingCalculationService $pricing)
    {
    }

    public function handle(PricingRequestData $request, SpatialDetectionData $spatial, array $workflow): PricingResultData
    {
        return $this->pricing->calculate($request, $spatial, $workflow);
    }
}
