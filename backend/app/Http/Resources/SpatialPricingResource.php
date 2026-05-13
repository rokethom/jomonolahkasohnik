<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Pricing\PricingResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpatialPricingResource extends JsonResource
{
    public function __construct(PricingResultData $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var PricingResultData $result */
        $result = $this->resource;

        return [
            'branch' => $result->branch?->name,
            'area' => $result->area?->name,
            'distance_km' => round($result->distanceKm, 2),
            'pricing_ring' => $result->pricingRing->name,
            'formula_type' => $result->pricingRing->formula_type,
            'price' => $result->price,
            'pricing_formula' => $result->formula,
            'metadata' => [
                'branch_id' => $result->branch?->id,
                'area_id' => $result->area?->id,
                'pricing_ring_id' => $result->pricingRing->id,
                'workflow' => $result->workflow,
            ],
        ];
    }
}
