<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofence_areas', function (Blueprint $table): void {
            $table->string('shape_type')->default('circle')->after('description')->index();
            $table->json('polygon_coordinates')->nullable()->after('radius_meters');
        });

        Schema::create('zone_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('geofence_area_id')->constrained()->cascadeOnDelete();
            $table->string('service_type')->nullable()->index();
            $table->string('match_point')->default('destination')->index();
            $table->string('price_mode')->default('fixed');
            $table->unsignedInteger('amount')->default(0);
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('min_km', 8, 2)->nullable();
            $table->decimal('max_km', 8, 2)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('priority')->default(0)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'geofence_area_id', 'is_active', 'priority'], 'zone_pricing_branch_area_active_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_pricing_rules');

        Schema::table('geofence_areas', function (Blueprint $table): void {
            $table->dropColumn(['shape_type', 'polygon_coordinates']);
        });
    }
};
