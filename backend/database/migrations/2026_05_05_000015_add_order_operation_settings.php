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
            ['key' => 'order_close_enabled', 'value' => 'true', 'is_active' => true, 'meta' => null],
            ['key' => 'order_close_start', 'value' => '01:00', 'is_active' => true, 'meta' => null],
            ['key' => 'order_close_end', 'value' => '05:00', 'is_active' => true, 'meta' => null],
            ['key' => 'order_close_message', 'value' => 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.', 'is_active' => true, 'meta' => null],
            ['key' => 'night_tariff_enabled', 'value' => 'true', 'is_active' => true, 'meta' => null],
            [
                'key' => 'night_tariff_rules',
                'value' => json_encode([
                    ['area' => 'bws', 'start' => '21:30', 'end' => '00:00', 'percent' => 30],
                    ['area' => 'bondowoso', 'start' => '21:30', 'end' => '00:00', 'percent' => 30],
                    ['area' => '', 'start' => '22:00', 'end' => '00:00', 'percent' => 30],
                    ['area' => '', 'start' => '00:01', 'end' => '04:00', 'percent' => 50],
                    ['area' => '', 'start' => '04:01', 'end' => '06:00', 'percent' => 30],
                ]),
                'is_active' => true,
                'meta' => null,
            ],
        ];
    }
};
