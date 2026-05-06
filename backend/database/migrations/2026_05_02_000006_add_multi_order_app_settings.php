<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            ['key' => 'multi_order_enabled', 'value' => 'false', 'is_active' => true, 'meta' => null],
            ['key' => 'max_multi_order', 'value' => '3', 'is_active' => true, 'meta' => null],
        ] as $setting) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [...$setting, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', ['multi_order_enabled', 'max_multi_order'])->delete();
    }
};
