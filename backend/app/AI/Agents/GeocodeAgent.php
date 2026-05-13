<?php

declare(strict_types=1);

namespace App\AI\Agents;

use App\DTOs\Pricing\PricingRequestData;

class GeocodeAgent
{
    public function handle(PricingRequestData $request): array
    {
        return [
            'pickup' => ['lat' => $request->pickupLatitude, 'lng' => $request->pickupLongitude],
            'destination' => ['lat' => $request->destinationLatitude, 'lng' => $request->destinationLongitude],
        ];
    }
}
