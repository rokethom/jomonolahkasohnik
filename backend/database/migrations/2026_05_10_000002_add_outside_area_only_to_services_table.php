<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'outside_area_only')) {
                $table->boolean('outside_area_only')->default(false)->after('whatsapp_redirect_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'outside_area_only')) {
                $table->dropColumn('outside_area_only');
            }
        });
    }
};
