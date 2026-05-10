<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ($this->defaults() as $setting) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [...$setting, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', array_column($this->defaults(), 'key'))->delete();
    }

    private function defaults(): array
    {
        return [
            ['key' => 'multi_crew_auto_cancel_enabled', 'value' => 'true', 'is_active' => true, 'meta' => null],
            ['key' => 'multi_crew_auto_cancel_minutes', 'value' => '7', 'is_active' => true, 'meta' => null],
            [
                'key' => 'multi_crew_auto_cancel_message',
                'value' => 'Maaf, order {order_code} dibatalkan otomatis karena {helper_label} belum menerima dalam {minutes} menit.',
                'is_active' => true,
                'meta' => null,
            ],
        ];
    }
};
