<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('manual_request_key', 100)->nullable()->after('source');
            $table->unsignedSmallInteger('manual_request_sequence')->nullable()->after('manual_request_key');
            $table->unique(['manual_request_key', 'manual_request_sequence'], 'orders_manual_request_sequence_unique');
        });

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

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_manual_request_sequence_unique');
            $table->dropColumn(['manual_request_key', 'manual_request_sequence']);
        });
    }

    private function defaults(): array
    {
        return [
            ['key' => 'driver_complete_wait_minutes', 'value' => '5', 'is_active' => true, 'meta' => null],
            ['key' => 'driver_adjustment_enabled', 'value' => 'true', 'is_active' => true, 'meta' => null],
            ['key' => 'driver_adjustment_min_amount', 'value' => '1000', 'is_active' => true, 'meta' => null],
            ['key' => 'driver_adjustment_max_amount', 'value' => '500000', 'is_active' => true, 'meta' => null],
            ['key' => 'driver_adjustment_step_amount', 'value' => '1000', 'is_active' => true, 'meta' => null],
            ['key' => 'driver_adjustment_default_amount', 'value' => '2000', 'is_active' => true, 'meta' => null],
        ];
    }
};
