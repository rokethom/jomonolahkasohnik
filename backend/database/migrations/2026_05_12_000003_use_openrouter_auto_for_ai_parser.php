<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'ai_provider' => 'openrouter',
            'ai_model' => 'openrouter/auto',
            'ai_base_url' => 'https://openrouter.ai/api/v1',
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
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'ai_model')
            ->where('value', 'openrouter/auto')
            ->update([
                'value' => 'openrouter/free',
                'updated_at' => now(),
            ]);
    }
};
