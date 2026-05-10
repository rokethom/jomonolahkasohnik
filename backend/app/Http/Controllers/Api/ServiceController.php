<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;

class ServiceController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Service::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'form_schema', 'whatsapp_redirect_enabled', 'outside_area_only', 'whatsapp_number', 'whatsapp_message_template']),
        ]);
    }
}
