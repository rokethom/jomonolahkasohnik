<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_crew_rules', function (Blueprint $table): void {
            $table->decimal('helper_base_distance_km', 8, 2)->default(10)->after('helper_service_charge');
            $table->unsignedInteger('helper_base_price')->default(6000)->after('helper_base_distance_km');
            $table->decimal('helper_over_distance_percent', 5, 2)->default(50)->after('helper_base_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_crew_rules', function (Blueprint $table): void {
            $table->dropColumn([
                'helper_base_distance_km',
                'helper_base_price',
                'helper_over_distance_percent',
            ]);
        });
    }
};
