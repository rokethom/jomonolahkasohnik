<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\AI\Orchestra\JojobotPricingOrchestra;
use App\DTOs\Pricing\PricingRequestData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CalculateSpatialPricingRequest;
use App\Http\Resources\SpatialPricingResource;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SpatialPricingController extends Controller
{
    public function calculate(CalculateSpatialPricingRequest $request, JojobotPricingOrchestra $orchestra): JsonResponse
    {
        try {
            $result = $orchestra->calculate(PricingRequestData::fromArray($request->validated()));
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => (new SpatialPricingResource($result))->toArray($request),
        ]);
    }
}
