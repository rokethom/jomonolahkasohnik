<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_driver_preferences')) {
            return;
        }

        Schema::create('customer_driver_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('type', 20)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'driver_id', 'type'], 'customer_driver_pref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_driver_preferences');
    }
};
