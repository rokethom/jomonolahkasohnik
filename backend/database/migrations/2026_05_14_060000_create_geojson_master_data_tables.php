<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'is_active')) {
            Schema::table('branches', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('longitude')->index();
            });
        }

        if (! Schema::hasTable('areas')) {
            Schema::create('areas', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 40)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['branch_id', 'code']);
                $table->index(['branch_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('geojson_regions')) {
            Schema::create('geojson_regions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->string('name');
                $table->json('geojson');
                $table->string('geometry_type', 40);
                $table->json('coordinates');
                $table->decimal('centroid_lat', 11, 8)->nullable();
                $table->decimal('centroid_lng', 11, 8)->nullable();
                $table->decimal('min_lat', 11, 8)->index();
                $table->decimal('max_lat', 11, 8)->index();
                $table->decimal('min_lng', 11, 8)->index();
                $table->decimal('max_lng', 11, 8)->index();
                $table->unsignedInteger('version')->default(1);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['branch_id', 'area_id', 'is_active']);
                $table->index(['min_lat', 'max_lat', 'min_lng', 'max_lng'], 'geojson_regions_bbox_index');
            });
        }

        $this->seedDefaultMasterRingControls();
    }

    public function down(): void
    {
        // Intentionally keep master data tables. Dropping GeoJSON regions can remove production coverage data.
    }

    private function seedDefaultMasterRingControls(): void
    {
        if (! Schema::hasTable('ring_pricing_rules') || ! Schema::hasColumn('ring_pricing_rules', 'min_km')) {
            return;
        }

        $now = now();
        foreach ([
            ['name' => 'Default Ring 1', 'ring' => 'ring_1', 'min_km' => 0, 'max_km' => 4, 'pricing_mode' => 'flat', 'price' => 6000, 'per_km_rate' => null, 'subtract_value' => 0, 'service_fee' => 1000, 'priority' => 300],
            ['name' => 'Default Ring 2', 'ring' => 'ring_2', 'min_km' => 4.1, 'max_km' => 9, 'pricing_mode' => 'flat', 'price' => 12000, 'per_km_rate' => null, 'subtract_value' => 0, 'service_fee' => 1000, 'priority' => 200],
            ['name' => 'Default Ring 3', 'ring' => 'ring_3', 'min_km' => 9.1, 'max_km' => null, 'pricing_mode' => 'formula', 'price' => 0, 'per_km_rate' => 1900, 'subtract_value' => 7000, 'service_fee' => 0, 'priority' => 100],
        ] as $rule) {
            $payload = [
                ...$rule,
                'branch_id' => null,
                'service_type' => null,
                'pickup_area' => '*',
                'destination_area' => '*',
                'pickup_aliases' => null,
                'destination_aliases' => null,
                'is_bidirectional' => true,
                'source' => 'manual',
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ];

            if (Schema::hasColumn('ring_pricing_rules', 'area_mode')) {
                $payload['area_mode'] = 'text';
            }

            if (Schema::hasColumn('ring_pricing_rules', 'match_type')) {
                $payload['match_type'] = 'point';
            }

            DB::table('ring_pricing_rules')->updateOrInsert(
                ['branch_id' => null, 'service_type' => null, 'ring' => $rule['ring'], 'pickup_area' => '*', 'destination_area' => '*'],
                $payload,
            );
        }
    }
};
