<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'allowed_service_types')) {
                $table->json('allowed_service_types')->nullable()->after('vehicle_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'allowed_service_types')) {
                $table->dropColumn('allowed_service_types');
            }
        });
    }
};
