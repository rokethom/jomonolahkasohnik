<?php

declare(strict_types=1);

namespace App\AI\Pipelines;

use App\AI\Orchestra\JojobotPricingOrchestra;
use App\DTOs\Pricing\PricingRequestData;
use App\DTOs\Pricing\PricingResultData;

class PricingPipeline
{
    public function __construct(private readonly JojobotPricingOrchestra $orchestra)
    {
    }

    public function run(PricingRequestData $request): PricingResultData
    {
        return $this->orchestra->calculate($request);
    }
}
