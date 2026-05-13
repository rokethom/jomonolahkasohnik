<?php

declare(strict_types=1);

namespace App\AI\Orchestra;

use App\AI\Agents\AnalyticsAgent;
use App\AI\Agents\GeocodeAgent;
use App\AI\Agents\PricingAgent;
use App\AI\Agents\RuleEngineAgent;
use App\AI\Agents\SpatialAgent;
use App\DTOs\Pricing\PricingRequestData;
use App\DTOs\Pricing\PricingResultData;
use App\Events\PricingCalculated;

class JojobotPricingOrchestra
{
    public function __construct(
        private readonly GeocodeAgent $geocode,
        private readonly SpatialAgent $spatial,
        private readonly PricingAgent $pricing,
        private readonly RuleEngineAgent $rules,
        private readonly AnalyticsAgent $analytics,
    ) {
    }

    public function calculate(PricingRequestData $request): PricingResultData
    {
        $started = microtime(true);
        $geocode = $this->geocode->handle($request);
        $spatial = $this->spatial->handle($request);
        $rules = $this->rules->activeRules();

        $workflow = [
            'orchestra' => 'Jojobot AI Orchestra Spatial Pricing Engine',
            'geocode' => $geocode,
            'spatial_source' => $spatial->source,
            'active_ai_rules' => count($rules),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        $result = $this->pricing->handle($request, $spatial, $workflow);
        PricingCalculated::dispatch($result);
        $this->analytics->handle($result);

        return $result;
    }
}
