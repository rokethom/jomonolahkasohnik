<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_pois')) {
            Schema::create('location_pois', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->foreignId('geojson_region_id')->nullable()->constrained('geojson_regions')->nullOnDelete();
                $table->string('name');
                $table->json('aliases')->nullable();
                $table->string('category', 50)->nullable()->index();
                $table->decimal('latitude', 11, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->string('source', 32)->default('manual')->index();
                $table->unsignedSmallInteger('confidence')->default(80)->index();
                $table->unsignedInteger('priority')->default(0)->index();
                $table->unsignedInteger('hit_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->boolean('is_active')->default(false)->index();
                $table->timestamps();

                $table->index(['branch_id', 'is_active']);
                $table->index(['latitude', 'longitude']);
            });
        }

        if (! Schema::hasTable('ai_location_suggestions')) {
            Schema::create('ai_location_suggestions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->foreignId('location_poi_id')->nullable()->constrained('location_pois')->nullOnDelete();
                $table->string('location_text');
                $table->string('normalized_text')->index();
                $table->string('role', 32)->default('destination')->index();
                $table->string('service_type', 50)->nullable()->index();
                $table->json('aliases')->nullable();
                $table->longText('raw_text')->nullable();
                $table->json('example_payload')->nullable();
                $table->decimal('latitude', 11, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->unsignedInteger('occurrence_count')->default(1)->index();
                $table->unsignedSmallInteger('confidence')->default(60)->index();
                $table->string('status', 32)->default('pending')->index();
                $table->timestamps();

                $table->unique(['branch_id', 'normalized_text', 'role'], 'ai_location_unique_suggestion');
                $table->index(['branch_id', 'status']);
            });
        }

        foreach ([
            ['key' => 'google_maps_distance_enabled', 'value' => '0', 'is_active' => true],
            ['key' => 'osrm_base_url', 'value' => 'https://router.project-osrm.org', 'is_active' => true],
        ] as $setting) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'is_active' => $setting['is_active'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
};
