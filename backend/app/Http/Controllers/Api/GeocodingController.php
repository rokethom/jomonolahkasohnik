<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\GeocodingService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GeocodingController extends Controller
{
    public function __invoke(Request $request, GeocodingService $geocoding, PricingService $pricing): JsonResponse
    {
        $payload = $request->validate([
            'address' => ['required', 'string', 'min:3', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'service_type' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'stops' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $branch = isset($payload['branch_id']) && $payload['branch_id']
                ? Branch::query()->find((int) $payload['branch_id'])
                : $request->user()?->branch;
            $destination = $geocoding->geocodeNearBranch($payload['address'], $branch);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $data = [
            'destination' => [
                'text' => $payload['address'],
                'lat' => $destination['lat'],
                'lng' => $destination['lng'],
                'formatted_address' => $destination['formatted_address'],
                'provider' => $destination['provider'],
            ],
        ];

        if (isset($payload['pickup_lat'], $payload['pickup_lng'])) {
            $quote = $pricing->calculate([
                'service_type' => $payload['service_type'] ?? 'ojek',
                'branch_id' => $payload['branch_id'] ?? null,
                'pickup_address' => $payload['pickup_address'] ?? 'Pickup',
                'pickup_lat' => $payload['pickup_lat'],
                'pickup_lng' => $payload['pickup_lng'],
                'destination_address' => $destination['formatted_address'],
                'destination_text' => $payload['address'],
                'destination_lat' => $destination['lat'],
                'destination_lng' => $destination['lng'],
                'stops' => $payload['stops'] ?? 1,
                'notes' => $payload['notes'] ?? null,
            ]);

            $data['distance'] = $quote['distance'];
            $data['price'] = $quote['final_price'];
            $data['quote'] = $quote;
        }

        return response()->json(['data' => $data]);
    }
}
