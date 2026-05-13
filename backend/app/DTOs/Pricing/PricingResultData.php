<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

use App\Models\Area;
use App\Models\Branch;
use App\Models\PricingRing;

final readonly class PricingResultData
{
    public function __construct(
        public PricingRequestData $request,
        public float $distanceKm,
        public ?Branch $branch,
        public ?Area $area,
        public PricingRing $pricingRing,
        public int $price,
        public string $formula,
        public array $workflow = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'branch' => $this->branch?->name,
            'branch_id' => $this->branch?->id,
            'area' => $this->area?->name,
            'area_id' => $this->area?->id,
            'distance_km' => round($this->distanceKm, 2),
            'pricing_ring' => $this->pricingRing->name,
            'pricing_ring_id' => $this->pricingRing->id,
            'formula_type' => $this->pricingRing->formula_type,
            'pricing_formula' => $this->formula,
            'price' => $this->price,
            'workflow' => $this->workflow,
        ];
    }
}
