<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'ai_openrouter_free_auto_enabled' => 'true',
            'ai_location_learning_openrouter_enabled' => 'false',
        ] as $key => $value) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'key' => $key,
                    'value' => $value,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        DB::table('app_settings')
            ->where('key', 'ai_provider')
            ->update(['value' => 'openrouter', 'updated_at' => now()]);

        DB::table('app_settings')
            ->where('key', 'ai_model')
            ->update(['value' => 'openrouter/free', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'ai_openrouter_free_auto_enabled')
            ->update(['value' => 'false', 'updated_at' => now()]);

        DB::table('app_settings')
            ->where('key', 'ai_location_learning_openrouter_enabled')
            ->update(['value' => 'false', 'updated_at' => now()]);

        DB::table('app_settings')
            ->where('key', 'ai_model')
            ->where('value', 'openrouter/free')
            ->update(['value' => 'openrouter/auto', 'updated_at' => now()]);
    }
};
