<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->decimal('min_km', 8, 2)->default(0);
            $table->decimal('max_km', 8, 2)->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->boolean('is_formula')->default(false);
            $table->unsignedInteger('per_km_rate')->nullable();
            $table->unsignedInteger('subtract_value')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'min_km', 'max_km']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_settings');
    }
};
