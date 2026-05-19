<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

class ServiceController extends Controller
{
    public function index()
    {
        $columns = ['id', 'name', 'code'];
        foreach (['sort_order', 'form_schema', 'whatsapp_redirect_enabled', 'outside_area_only', 'whatsapp_number', 'whatsapp_message_template'] as $column) {
            if (Schema::hasColumn('services', $column)) {
                $columns[] = $column;
            }
        }

        $query = Service::query()->where('is_active', true);
        if (Schema::hasColumn('services', 'sort_order')) {
            $query->orderBy('sort_order');
        }

        return response()->json([
            'data' => $query->orderBy('name')->get($columns),
        ]);
    }
}
