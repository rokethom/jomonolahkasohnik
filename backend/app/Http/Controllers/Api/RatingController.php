<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function store(Request $request, Order $order, RatingService $ratings): JsonResponse
    {
        $payload = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json([
            'message' => 'Rating tersimpan',
            'data' => $ratings->rate($order, $request->user(), (int) $payload['rating'], $payload['comment'] ?? null),
        ], 201);
    }
}
