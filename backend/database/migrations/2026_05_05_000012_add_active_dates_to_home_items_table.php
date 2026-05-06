<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_items', function (Blueprint $table): void {
            $table->dateTime('start_date')->nullable()->after('order')->index();
            $table->dateTime('end_date')->nullable()->after('start_date')->index();
        });
    }

    public function down(): void
    {
        Schema::table('home_items', function (Blueprint $table): void {
            $table->dropColumn(['start_date', 'end_date']);
        });
    }
};
