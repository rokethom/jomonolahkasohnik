<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'vehicle_types')) {
                $table->json('vehicle_types')->nullable()->after('vehicle_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'vehicle_types')) {
                $table->dropColumn('vehicle_types');
            }
        });
    }
};
