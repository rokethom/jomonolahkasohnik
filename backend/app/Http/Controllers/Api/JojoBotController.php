<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JojoBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JojoBotController extends Controller
{
    public function preview(Request $request, JojoBotService $jojoBot): JsonResponse
    {
        $data = $request->validate([
            'raw_text' => ['required', 'string', 'max:4000'],
        ]);

        $preview = $jojoBot->preview($request->user(), $data['raw_text']);

        return response()->json([
            'message' => $preview['message'] ?? $preview['reply'] ?? null,
            'form_schema' => $preview['form_schema'] ?? null,
            'service_type' => $preview['service_type'] ?? $preview['selected_service'] ?? null,
            'data' => $preview,
        ]);
    }
}
