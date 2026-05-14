<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'google_maps_geocode_enabled'],
            [
                'value' => '0',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
};
