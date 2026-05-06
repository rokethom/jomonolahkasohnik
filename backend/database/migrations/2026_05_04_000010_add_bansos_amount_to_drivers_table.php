<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('drivers', 'bansos_amount')) {
                $table->unsignedInteger('bansos_amount')->nullable()->after('bpjs_jht_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            if (Schema::hasColumn('drivers', 'bansos_amount')) {
                $table->dropColumn('bansos_amount');
            }
        });
    }
};
