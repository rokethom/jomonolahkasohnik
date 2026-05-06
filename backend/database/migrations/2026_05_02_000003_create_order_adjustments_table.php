<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->string('reason');
            $table->timestamps();

            $table->index(['order_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_adjustments');
    }
};
