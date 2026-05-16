<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            if (! Schema::hasColumn('branches', 'pricing_origin_name')) {
                $table->string('pricing_origin_name')->nullable()->after('radius_km');
            }

            if (! Schema::hasColumn('branches', 'pricing_origin_latitude')) {
                $table->decimal('pricing_origin_latitude', 11, 8)->nullable()->after('pricing_origin_name');
            }

            if (! Schema::hasColumn('branches', 'pricing_origin_longitude')) {
                $table->decimal('pricing_origin_longitude', 11, 8)->nullable()->after('pricing_origin_latitude');
            }
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->index(['pricing_origin_latitude', 'pricing_origin_longitude'], 'branches_pricing_origin_index');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropIndex('branches_pricing_origin_index');
        });

        Schema::table('branches', function (Blueprint $table): void {
            if (Schema::hasColumn('branches', 'pricing_origin_longitude')) {
                $table->dropColumn('pricing_origin_longitude');
            }

            if (Schema::hasColumn('branches', 'pricing_origin_latitude')) {
                $table->dropColumn('pricing_origin_latitude');
            }

            if (Schema::hasColumn('branches', 'pricing_origin_name')) {
                $table->dropColumn('pricing_origin_name');
            }
        });
    }
};
