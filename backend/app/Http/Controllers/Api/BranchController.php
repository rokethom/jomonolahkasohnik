<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Branch::query()
                ->with('geofenceAreas:id,branch_id,name,center_latitude,center_longitude,radius_meters,is_active')
                ->withCount('geofenceAreas')
                ->operationalAreas()
                ->orderBy('branch_code')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
