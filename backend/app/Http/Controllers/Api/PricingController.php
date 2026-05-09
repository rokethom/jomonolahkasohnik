<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\CalculatePricingRequest;
use App\Services\GeocodingService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class PricingController extends Controller
{
    public function calculate(CalculatePricingRequest $request, PricingService $pricingService, GeocodingService $geocoding): JsonResponse
    {
        $payload = $request->validated();
        $payload['branch_id'] ??= $request->user()?->branch_id;

        if ((! isset($payload['destination_lat'], $payload['destination_lng'])) && filled($payload['destination_text'] ?? null)) {
            $destination = $geocoding->geocode($payload['destination_text']);
            $payload['destination_lat'] = $destination['lat'];
            $payload['destination_lng'] = $destination['lng'];
            $payload['destination_address'] = $destination['formatted_address'];
        }

        try {
            $quote = $pricingService->calculate($payload);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Pricing calculated',
            'data' => [
                ...$quote,
                'destination' => isset($destination) ? [
                    'text' => $payload['destination_text'],
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                    'formatted_address' => $destination['formatted_address'],
                ] : null,
            ],
        ]);
    }
}
