<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_price_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type', 50)->nullable()->index();
            $table->string('status', 30)->default('pending')->index();
            $table->text('raw_text')->nullable();
            $table->json('parsed')->nullable();
            $table->json('order_payload');
            $table->json('quote');
            $table->unsignedInteger('system_price')->default(0);
            $table->unsignedInteger('system_service_fee')->default(0);
            $table->unsignedInteger('system_total_price')->default(0);
            $table->unsignedInteger('corrected_price')->nullable();
            $table->unsignedInteger('corrected_service_fee')->nullable();
            $table->integer('corrected_extra_charge')->default(0);
            $table->unsignedInteger('corrected_total_price')->nullable();
            $table->string('correction_reason', 500)->nullable();
            $table->timestamp('confirmation_available_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_price_reviews');
    }
};
