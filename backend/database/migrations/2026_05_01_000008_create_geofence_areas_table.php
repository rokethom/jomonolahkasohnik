<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofence_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('center_latitude', 11, 8);
            $table->decimal('center_longitude', 11, 8);
            $table->unsignedInteger('radius_meters');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'is_active', 'priority']);
            $table->index(['center_latitude', 'center_longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_areas');
    }
};
