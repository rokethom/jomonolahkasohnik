<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('cancelled_at')->index();
            }
        });

        if (Schema::hasColumn('orders', 'completed_at')) {
            DB::table('orders')
                ->where('status', 'COMPLETED')
                ->whereNull('completed_at')
                ->update(['completed_at' => DB::raw('updated_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
