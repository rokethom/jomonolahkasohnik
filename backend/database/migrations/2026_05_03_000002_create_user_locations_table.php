<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('lat', 11, 8);
            $table->decimal('lng', 11, 8);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('geofence_area_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('distance_meters', 10, 2)->nullable();
            $table->string('status')->default('outside_branch');
            $table->timestamp('gps_timestamp')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_locations');
    }
};
