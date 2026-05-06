<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('direction_bearing', 8, 4)->nullable()->after('distance_km');
            $table->boolean('is_multi_order')->default(false)->after('direction_bearing')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['direction_bearing', 'is_multi_order']);
        });
    }
};
