<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('orders', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('cancelled_at')->index();
            }
            if (! Schema::hasColumn('orders', 'geocoded_by')) {
                $table->string('geocoded_by', 30)->nullable()->after('destination_lng');
            }
            if (! Schema::hasColumn('orders', 'locked_location_hash')) {
                $table->string('locked_location_hash', 64)->nullable()->after('geocoded_by');
            }
            if (! Schema::hasColumn('orders', 'device_location_log_id')) {
                $table->foreignId('device_location_log_id')->nullable()->after('locked_location_hash')->constrained('location_logs')->nullOnDelete();
            }
        });

        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'bpjs_jht_enabled')) {
                $table->boolean('bpjs_jht_enabled')->default(false)->after('suspended_until');
            }
            if (! Schema::hasColumn('drivers', 'permanent_delete_eligible_at')) {
                $table->timestamp('permanent_delete_eligible_at')->nullable()->after('bpjs_jht_enabled')->index();
            }
        });

        Schema::table('driver_suspensions', function (Blueprint $table): void {
            if (! Schema::hasColumn('driver_suspensions', 'type')) {
                $table->string('type', 30)->default('violation')->after('created_by')->index();
            }
            if (! Schema::hasColumn('driver_suspensions', 'metadata')) {
                $table->json('metadata')->nullable()->after('status');
            }
        });

        Schema::create('order_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('label');
            $table->string('address');
            $table->decimal('lat', 11, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            $table->string('geocoded_by', 30)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'sequence']);
        });

        Schema::create('driver_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('handle_day_15')->default(0);
            $table->unsignedInteger('handle_day_30')->default(0);
            $table->unsignedInteger('bansos')->default(0);
            $table->unsignedInteger('bpjs')->default(0);
            $table->unsignedInteger('bpjs_jht')->default(0);
            $table->unsignedInteger('paid_amount')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 30)->default('unpaid')->index();
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'year', 'month']);
        });

        Schema::create('ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'rating']);
        });

        Schema::create('oper_handle_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('operator_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('spv_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proof_path')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('operator_approved_at')->nullable();
            $table->timestamp('spv_approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oper_handle_requests');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('driver_deposits');
        Schema::dropIfExists('order_points');

        Schema::table('driver_suspensions', function (Blueprint $table): void {
            foreach (['type', 'metadata'] as $column) {
                if (Schema::hasColumn('driver_suspensions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('drivers', function (Blueprint $table): void {
            foreach (['bpjs_jht_enabled', 'permanent_delete_eligible_at'] as $column) {
                if (Schema::hasColumn('drivers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'device_location_log_id')) {
                $table->dropConstrainedForeignId('device_location_log_id');
            }
            foreach (['cancelled_at', 'expired_at', 'geocoded_by', 'locked_location_hash'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
