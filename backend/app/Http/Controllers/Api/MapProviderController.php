<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;

class MapProviderController extends Controller
{
    public function __invoke(SettingService $settings)
    {
        return response()->json([
            'data' => $settings->getMapProvider(),
        ]);
    }
}
