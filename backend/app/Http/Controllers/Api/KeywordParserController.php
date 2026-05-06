<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KeywordParserService;
use Illuminate\Http\JsonResponse;

class KeywordParserController extends Controller
{
    public function index(KeywordParserService $keywords): JsonResponse
    {
        return response()->json([
            'data' => $keywords->allActive(),
        ]);
    }
}
