<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('pricing_keyword_rules') && Schema::hasColumn('pricing_keyword_rules', 'amount')) {
            DB::statement('ALTER TABLE pricing_keyword_rules MODIFY amount INT NOT NULL DEFAULT 0');
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'extra_charge')) {
            DB::statement('ALTER TABLE orders MODIFY extra_charge INT NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('pricing_keyword_rules') && Schema::hasColumn('pricing_keyword_rules', 'amount')) {
            DB::table('pricing_keyword_rules')->where('amount', '<', 0)->update(['amount' => 0]);
            DB::statement('ALTER TABLE pricing_keyword_rules MODIFY amount INT UNSIGNED NOT NULL DEFAULT 0');
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'extra_charge')) {
            DB::table('orders')->where('extra_charge', '<', 0)->update(['extra_charge' => 0]);
            DB::statement('ALTER TABLE orders MODIFY extra_charge INT UNSIGNED NOT NULL DEFAULT 0');
        }
    }
};
