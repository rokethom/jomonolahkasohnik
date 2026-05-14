<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_alias_maps')) {
            return;
        }

        Schema::create('ai_alias_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('geojson_region_id')->nullable()->constrained('geojson_regions')->nullOnDelete();
            $table->string('canonical_name');
            $table->json('aliases')->nullable();
            $table->string('source', 32)->default('manual')->index();
            $table->unsignedSmallInteger('confidence')->default(80)->index();
            $table->unsignedInteger('priority')->default(0)->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
            $table->index(['geojson_region_id', 'is_active']);
        });
    }
};
