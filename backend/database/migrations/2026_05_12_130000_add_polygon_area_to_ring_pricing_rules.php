<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ring_pricing_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('ring_pricing_rules', 'area_mode')) {
                $table->string('area_mode', 24)->default('text')->after('name')->index();
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'polygon_coordinates')) {
                $table->json('polygon_coordinates')->nullable()->after('destination_aliases');
            }

            if (! Schema::hasColumn('ring_pricing_rules', 'polygon_match_point')) {
                $table->string('polygon_match_point', 32)->default('destination_then_pickup')->after('polygon_coordinates')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ring_pricing_rules', function (Blueprint $table): void {
            foreach (['polygon_match_point', 'polygon_coordinates', 'area_mode'] as $column) {
                if (Schema::hasColumn('ring_pricing_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
