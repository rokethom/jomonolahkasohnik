<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active')->index();
            }
        });

        $sortMap = [
            'BL' => 10,
            'DO' => 20,
            'GO' => 30,
            'JM' => 40,
            'KR' => 50,
            'LP' => 60,
            'OJ' => 70,
            'PK' => 80,
            'TV' => 90,
        ];

        foreach ($sortMap as $code => $sortOrder) {
            DB::table('services')
                ->where('code', $code)
                ->update(['sort_order' => $sortOrder, 'updated_at' => now()]);
        }

        $next = 100;
        DB::table('services')
            ->where('sort_order', 0)
            ->orderBy('name')
            ->get(['id'])
            ->each(function (object $service) use (&$next): void {
                DB::table('services')
                    ->where('id', $service->id)
                    ->update(['sort_order' => $next, 'updated_at' => now()]);
                $next += 10;
            });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
