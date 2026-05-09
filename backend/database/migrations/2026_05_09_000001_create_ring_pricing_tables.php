<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ring_pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type')->nullable()->index();
            $table->string('name');
            $table->string('pickup_area');
            $table->string('destination_area');
            $table->json('pickup_aliases')->nullable();
            $table->json('destination_aliases')->nullable();
            $table->string('ring', 40)->index();
            $table->unsignedInteger('price');
            $table->boolean('is_bidirectional')->default(true);
            $table->string('source', 40)->default('manual')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'service_type', 'is_active']);
        });

        Schema::create('ring_pricing_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type')->nullable()->index();
            $table->string('pickup_area');
            $table->string('destination_area');
            $table->string('ring', 40)->nullable();
            $table->unsignedInteger('suggested_price');
            $table->unsignedInteger('previous_price')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->json('sample_order_ids')->nullable();
            $table->foreignId('last_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'service_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ring_pricing_suggestions');
        Schema::dropIfExists('ring_pricing_rules');
    }
};
