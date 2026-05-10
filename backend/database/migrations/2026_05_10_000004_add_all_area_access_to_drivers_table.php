<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'can_accept_all_areas')) {
                $table->boolean('can_accept_all_areas')->default(false)->after('allowed_service_types');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'can_accept_all_areas')) {
                $table->dropColumn('can_accept_all_areas');
            }
        });
    }
};
