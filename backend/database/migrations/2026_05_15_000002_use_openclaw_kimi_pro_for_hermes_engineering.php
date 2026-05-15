<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'hermes_provider' => 'openclaw',
            'hermes_model' => 'kimi-pro',
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
            ->where('key', 'hermes_provider')
            ->where('value', 'openclaw')
            ->update([
                'value' => 'openai_compatible',
                'updated_at' => now(),
            ]);

        DB::table('app_settings')
            ->where('key', 'hermes_model')
            ->where('value', 'kimi-pro')
            ->update([
                'value' => 'nousresearch/hermes-3-llama-3.1-405b',
                'updated_at' => now(),
            ]);
    }
};
