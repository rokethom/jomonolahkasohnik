<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'vehicle_seat_rows')) {
                $table->unsignedTinyInteger('vehicle_seat_rows')->nullable()->after('vehicle_type')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'vehicle_seat_rows')) {
                $table->dropColumn('vehicle_seat_rows');
            }
        });
    }
};
