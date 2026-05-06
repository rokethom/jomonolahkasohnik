<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 11, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('altitude', 10, 2)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->decimal('heading', 8, 2)->nullable();
            $table->timestamp('gps_timestamp')->nullable();
            $table->string('provider')->nullable();
            $table->boolean('is_mock_location')->default(false);
            $table->boolean('is_valid')->default(false);
            $table->foreignId('geofence_area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_suspicious')->default(false);
            $table->text('suspicion_reason')->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
            $table->index(['user_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['geofence_area_id', 'created_at']);
            $table->index(['is_valid', 'is_suspicious']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_logs');
    }
};
