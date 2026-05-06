<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->settings() as $setting) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [...$setting, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->whereIn('key', collect($this->settings())->pluck('key')->all())
            ->delete();
    }

    private function settings(): array
    {
        return [
            ['key' => 'ai_assistant_enabled', 'value' => 'false', 'is_active' => true, 'meta' => null],
            ['key' => 'ai_provider', 'value' => 'openai', 'is_active' => true, 'meta' => null],
            ['key' => 'ai_model', 'value' => null, 'is_active' => true, 'meta' => null],
            ['key' => 'ai_base_url', 'value' => null, 'is_active' => true, 'meta' => null],
            ['key' => 'openai_api_key', 'value' => null, 'is_active' => false, 'meta' => null],
            ['key' => 'kimi_api_key', 'value' => null, 'is_active' => false, 'meta' => null],
            ['key' => 'blackbox_api_key', 'value' => null, 'is_active' => false, 'meta' => null],
        ];
    }
};
