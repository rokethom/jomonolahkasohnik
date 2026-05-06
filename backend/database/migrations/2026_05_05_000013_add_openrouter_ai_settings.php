<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['key' => 'openrouter_api_key', 'value' => null, 'is_active' => false, 'meta' => json_encode(['encrypted' => true])],
            ['key' => 'ai_max_tokens', 'value' => '700', 'is_active' => true, 'meta' => null],
        ] as $row) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $row['key']],
                array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'openrouter_api_key',
            'ai_max_tokens',
        ])->delete();
    }
};
