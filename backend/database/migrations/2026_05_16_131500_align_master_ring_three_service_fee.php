<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ring_pricing_rules')) {
            return;
        }

        DB::table('ring_pricing_rules')
            ->where('ring', 'ring_3')
            ->where('pickup_area', '*')
            ->where('destination_area', '*')
            ->where('pricing_mode', 'formula')
            ->where('per_km_rate', 1900)
            ->where('subtract_value', 7000)
            ->update([
                'service_fee' => 0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ring_pricing_rules')) {
            return;
        }

        DB::table('ring_pricing_rules')
            ->where('ring', 'ring_3')
            ->where('pickup_area', '*')
            ->where('destination_area', '*')
            ->where('pricing_mode', 'formula')
            ->where('per_km_rate', 1900)
            ->where('subtract_value', 7000)
            ->update([
                'service_fee' => 1000,
                'updated_at' => now(),
            ]);
    }
};
