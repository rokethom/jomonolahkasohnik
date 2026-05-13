<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('branches', 'code')) {
                $table->string('code', 40)->nullable()->after('id')->index();
            }

            if (! Schema::hasColumn('branches', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('longitude')->index();
            }
        });

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

        Schema::create('pricing_rings', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('min_km', 8, 2)->default(0)->index();
            $table->decimal('max_km', 8, 2)->nullable()->index();
            $table->string('formula_type', 40)->default('FIXED')->index();
            $table->unsignedInteger('base_price')->default(0);
            $table->unsignedInteger('service_fee')->default(0);
            $table->unsignedInteger('per_km_price')->nullable();
            $table->unsignedInteger('deduction')->default(0);
            $table->integer('priority')->default(0)->index();
            $table->date('effective_date')->nullable()->index();
            $table->date('expired_date')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['is_active', 'min_km', 'max_km', 'priority']);
        });

        Schema::create('pricing_logs', function (Blueprint $table): void {
            $table->id();
            $table->decimal('pickup_latitude', 11, 8);
            $table->decimal('pickup_longitude', 11, 8);
            $table->decimal('destination_latitude', 11, 8);
            $table->decimal('destination_longitude', 11, 8);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('pricing_ring_id')->nullable()->constrained('pricing_rings')->nullOnDelete();
            $table->decimal('distance_km', 8, 2);
            $table->unsignedInteger('calculated_price');
            $table->string('pricing_formula');
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'area_id', 'created_at']);
            $table->index(['pricing_ring_id', 'created_at']);
        });

        Schema::create('ai_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 80)->unique();
            $table->string('agent', 80)->index();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->integer('priority')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('provider', 80)->default('native_laravel');
            $table->string('model_key', 120)->unique();
            $table->json('configuration')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('ai_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('workflow', 120)->index();
            $table->string('agent', 120)->nullable()->index();
            $table->string('status', 40)->default('success')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
        });

        $this->seedDefaultPricingRings();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_logs');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_rules');
        Schema::dropIfExists('pricing_logs');
        Schema::dropIfExists('pricing_rings');
        Schema::dropIfExists('geojson_regions');
        Schema::dropIfExists('areas');
    }

    private function seedDefaultPricingRings(): void
    {
        $now = now();
        foreach ([
            ['name' => 'Ring 1', 'min_km' => 0, 'max_km' => 4, 'formula_type' => 'FIXED', 'base_price' => 6000, 'service_fee' => 1000, 'per_km_price' => null, 'deduction' => 0, 'priority' => 300],
            ['name' => 'Ring 2', 'min_km' => 4.1, 'max_km' => 9, 'formula_type' => 'FIXED', 'base_price' => 12000, 'service_fee' => 1000, 'per_km_price' => null, 'deduction' => 0, 'priority' => 200],
            ['name' => 'Ring 3', 'min_km' => 9.01, 'max_km' => null, 'formula_type' => 'DISTANCE', 'base_price' => 0, 'service_fee' => 0, 'per_km_price' => 1900, 'deduction' => 7000, 'priority' => 100],
        ] as $ring) {
            DB::table('pricing_rings')->updateOrInsert(
                ['name' => $ring['name']],
                [...$ring, 'is_active' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
