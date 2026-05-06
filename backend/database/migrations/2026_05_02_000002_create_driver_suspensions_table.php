<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_suspensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->unsignedInteger('duration');
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index(['start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_suspensions');
    }
};
