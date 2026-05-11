<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'zone_pricing_enabled'],
            [
                'value' => 'true',
                'is_active' => true,
                'meta' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'zone_pricing_enabled')->update([
            'value' => 'false',
            'updated_at' => now(),
        ]);
    }
};
