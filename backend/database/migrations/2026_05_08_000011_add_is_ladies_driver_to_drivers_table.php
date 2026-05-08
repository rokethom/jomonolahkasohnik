<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'is_ladies_driver')) {
                $table->boolean('is_ladies_driver')->default(false)->after('vehicle_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'is_ladies_driver')) {
                $table->dropColumn('is_ladies_driver');
            }
        });
    }
};
