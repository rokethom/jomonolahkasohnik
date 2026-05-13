<?php

declare(strict_types=1);

namespace App\AI\Agents;

use App\DTOs\Pricing\PricingRequestData;
use App\DTOs\Pricing\SpatialDetectionData;
use App\Services\Spatial\SpatialDetectionService;

class SpatialAgent
{
    public function __construct(private readonly SpatialDetectionService $spatial)
    {
    }

    public function handle(PricingRequestData $request): SpatialDetectionData
    {
        return $this->spatial->detect($request->destinationLatitude, $request->destinationLongitude);
    }
}
