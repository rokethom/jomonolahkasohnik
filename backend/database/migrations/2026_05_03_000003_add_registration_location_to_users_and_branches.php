<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('lat', 11, 8)->nullable()->after('branch_id');
            $table->decimal('lng', 11, 8)->nullable()->after('lat');
            $table->text('address')->nullable()->after('lng');

            $table->index(['lat', 'lng']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('radius_km', 8, 2)->default(5)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('radius_km');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['lat', 'lng']);
            $table->dropColumn(['lat', 'lng', 'address']);
        });
    }
};
