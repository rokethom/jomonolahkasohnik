<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->date('daily_priority_date')->nullable()->after('is_available')->index();
            $table->boolean('daily_priority_active')->default(false)->after('daily_priority_date')->index();
            $table->timestamp('first_online_at')->nullable()->after('daily_priority_active');
            $table->timestamp('daily_priority_completed_at')->nullable()->after('first_online_at');
            $table->foreignId('daily_priority_order_id')->nullable()->after('daily_priority_completed_at')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('daily_priority_order_id');
            $table->dropColumn([
                'daily_priority_date',
                'daily_priority_active',
                'first_online_at',
                'daily_priority_completed_at',
            ]);
        });
    }
};
