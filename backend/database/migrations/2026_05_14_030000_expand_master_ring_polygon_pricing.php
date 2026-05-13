<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ring_pricing_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('ring_pricing_rules', 'min_km')) {
                $table->decimal('min_km', 8, 2)->default(0)->after('ring');
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'max_km')) {
                $table->decimal('max_km', 8, 2)->nullable()->after('min_km');
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'pricing_mode')) {
                $table->string('pricing_mode', 24)->default('flat')->after('max_km')->index();
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'per_km_rate')) {
                $table->unsignedInteger('per_km_rate')->nullable()->after('price');
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'subtract_value')) {
                $table->unsignedInteger('subtract_value')->default(0)->after('per_km_rate');
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'service_fee')) {
                $table->unsignedInteger('service_fee')->default(1000)->after('subtract_value');
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'priority')) {
                $table->integer('priority')->default(0)->after('service_fee')->index();
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'match_type')) {
                $table->string('match_type', 24)->default('point')->after('polygon_match_point')->index();
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'pickup_ring')) {
                $table->string('pickup_ring', 40)->nullable()->after('match_type')->index();
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'destination_ring')) {
                $table->string('destination_ring', 40)->nullable()->after('pickup_ring')->index();
            }
        });

        $defaults = [
            'ring_1' => ['min_km' => 0, 'max_km' => 4, 'pricing_mode' => 'flat', 'price' => 6000, 'per_km_rate' => null, 'subtract_value' => 0, 'service_fee' => 1000, 'priority' => 300],
            'ring_2' => ['min_km' => 4.1, 'max_km' => 9, 'pricing_mode' => 'flat', 'price' => 12000, 'per_km_rate' => null, 'subtract_value' => 0, 'service_fee' => 1000, 'priority' => 200],
            'ring_3' => ['min_km' => 9.1, 'max_km' => null, 'pricing_mode' => 'formula', 'price' => 0, 'per_km_rate' => 1900, 'subtract_value' => 7000, 'service_fee' => 0, 'priority' => 100],
        ];

        foreach ($defaults as $ring => $values) {
            DB::table('ring_pricing_rules')
                ->where('ring', $ring)
                ->update([...$values, 'updated_at' => now()]);
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'zone_pricing_enabled'],
            ['value' => '0', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::table('ring_pricing_rules', function (Blueprint $table): void {
            foreach (['destination_ring', 'pickup_ring', 'match_type', 'priority', 'service_fee', 'subtract_value', 'per_km_rate', 'pricing_mode', 'max_km', 'min_km'] as $column) {
                if (Schema::hasColumn('ring_pricing_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
