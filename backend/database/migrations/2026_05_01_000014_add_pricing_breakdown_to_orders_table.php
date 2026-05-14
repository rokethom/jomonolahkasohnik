<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('distance_km', 8, 2)->default(0)->after('destination_lng');
            $table->integer('extra_charge')->default(0)->after('service_charge');
            $table->unsignedInteger('stops')->default(1)->after('extra_charge');
            $table->json('pricing_breakdown')->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['distance_km', 'extra_charge', 'stops', 'pricing_breakdown']);
        });
    }
};
