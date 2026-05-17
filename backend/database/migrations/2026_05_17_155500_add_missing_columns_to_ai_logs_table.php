<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_logs')) {
            return;
        }

        Schema::table('ai_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_logs', 'source')) {
                $table->string('source', 80)->default('unknown')->index();
            }

            if (! Schema::hasColumn('ai_logs', 'workflow')) {
                $table->string('workflow', 100)->default('unknown')->index()->after('source');
            }

            if (! Schema::hasColumn('ai_logs', 'event')) {
                $table->string('event', 100)->default('unknown')->index();
            }

            if (! Schema::hasColumn('ai_logs', 'status')) {
                $table->string('status', 30)->default('success')->index();
            }

            if (! Schema::hasColumn('ai_logs', 'queue')) {
                $table->string('queue', 50)->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'actor_id')) {
                $table->unsignedBigInteger('actor_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'live_price_review_id')) {
                $table->unsignedBigInteger('live_price_review_id')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'provider')) {
                $table->string('provider', 80)->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'model')) {
                $table->string('model', 160)->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'input_payload')) {
                $table->json('input_payload')->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'output_payload')) {
                $table->json('output_payload')->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'message')) {
                $table->text('message')->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'error_message')) {
                $table->text('error_message')->nullable();
            }

            if (! Schema::hasColumn('ai_logs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->index();
            }

            if (! Schema::hasColumn('ai_logs', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Intentionally left blank. This migration repairs a production table
        // that may already contain legacy AI log data.
    }
};
