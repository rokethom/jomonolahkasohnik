<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;

class SettingsController extends Controller
{
    public function publicSettings(SettingService $settings)
    {
        return response()->json([
            'data' => $settings->publicSettings(),
        ]);
    }
}
