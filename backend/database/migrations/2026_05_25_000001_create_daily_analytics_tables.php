<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_service_analytics', function (Blueprint $table): void {
            $table->id();
            $table->date('analytics_date');
            $table->string('service_code', 32);
            $table->string('service_name')->nullable();
            $table->boolean('is_total')->default(false);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('completed_orders')->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->unsignedInteger('unique_customers')->default(0);
            $table->unsignedInteger('repeat_customers')->default(0);
            $table->unsignedBigInteger('gross_revenue')->default(0);
            $table->unsignedBigInteger('net_revenue')->default(0);
            $table->unsignedBigInteger('driver_payout')->default(0);
            $table->unsignedBigInteger('platform_fee')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
            $table->unsignedInteger('ratings_count')->default(0);
            $table->json('hourly_orders')->nullable();
            $table->timestamps();

            $table->unique(['analytics_date', 'service_code'], 'daily_service_analytics_date_service_unique');
            $table->index(['analytics_date', 'is_total'], 'daily_service_analytics_date_total_idx');
        });

        Schema::create('daily_driver_analytics', function (Blueprint $table): void {
            $table->id();
            $table->date('analytics_date');
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('assigned_orders')->default(0);
            $table->unsignedInteger('accepted_orders')->default(0);
            $table->unsignedInteger('completed_orders')->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->unsignedBigInteger('gross_revenue')->default(0);
            $table->unsignedBigInteger('driver_payout')->default(0);
            $table->unsignedBigInteger('platform_fee')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
            $table->unsignedInteger('ratings_count')->default(0);
            $table->timestamps();

            $table->unique(['analytics_date', 'driver_id'], 'daily_driver_analytics_date_driver_unique');
            $table->index(['analytics_date', 'completed_orders'], 'daily_driver_analytics_rank_idx');
        });

        Schema::create('daily_area_analytics', function (Blueprint $table): void {
            $table->id();
            $table->date('analytics_date');
            $table->string('area_key', 40);
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('area_name')->default('Tanpa Area');
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('completed_orders')->default(0);
            $table->unsignedInteger('cancelled_orders')->default(0);
            $table->unsignedBigInteger('gross_revenue')->default(0);
            $table->unsignedBigInteger('platform_fee')->default(0);
            $table->timestamps();

            $table->unique(['analytics_date', 'area_key'], 'daily_area_analytics_date_area_unique');
            $table->index(['analytics_date', 'gross_revenue'], 'daily_area_analytics_revenue_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['created_at', 'service_code', 'status'], 'analytics_orders_date_service_status_idx');
            $table->index(['created_at', 'driver_id', 'status'], 'analytics_orders_date_driver_status_idx');
            $table->index(['created_at', 'area_id', 'status'], 'analytics_orders_date_area_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('analytics_orders_date_service_status_idx');
            $table->dropIndex('analytics_orders_date_driver_status_idx');
            $table->dropIndex('analytics_orders_date_area_status_idx');
        });

        Schema::dropIfExists('daily_area_analytics');
        Schema::dropIfExists('daily_driver_analytics');
        Schema::dropIfExists('daily_service_analytics');
    }
};
