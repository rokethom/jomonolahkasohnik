<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CmsHomeService;
use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function __invoke(CmsHomeService $cms): JsonResponse
    {
        return response()->json($cms->payload());
    }
}
