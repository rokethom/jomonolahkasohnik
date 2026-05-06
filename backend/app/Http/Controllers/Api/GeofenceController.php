<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeofenceArea;
use Illuminate\Http\JsonResponse;

class GeofenceController extends Controller
{
    public function index(int $branchId): JsonResponse
    {
        return response()->json([
            'data' => GeofenceArea::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
