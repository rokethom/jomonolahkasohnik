<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('price_settings', 'is_active')) {
            Schema::table('price_settings', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('subtract_value')->index();
            });
        }

        DB::table('price_settings')->whereNull('is_active')->update(['is_active' => true]);

        $now = now();
        foreach ($this->globalRules() as $rule) {
            DB::table('price_settings')->updateOrInsert(
                ['name' => $rule['name'], 'branch_id' => null],
                [
                    ...$rule,
                    'branch_id' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('price_settings')
            ->whereNull('branch_id')
            ->whereIn('name', array_column($this->globalRules(), 'name'))
            ->delete();

        if (Schema::hasColumn('price_settings', 'is_active')) {
            Schema::table('price_settings', function (Blueprint $table): void {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            });
        }
    }

    private function globalRules(): array
    {
        return [
            [
                'name' => 'Global 0-5 KM',
                'min_km' => 0,
                'max_km' => 5,
                'price' => 6000,
                'is_formula' => false,
                'per_km_rate' => null,
                'subtract_value' => 0,
            ],
            [
                'name' => 'Global 5-10 KM',
                'min_km' => 5.01,
                'max_km' => 10,
                'price' => 12000,
                'is_formula' => false,
                'per_km_rate' => null,
                'subtract_value' => 0,
            ],
            [
                'name' => 'Global >10 KM',
                'min_km' => 10,
                'max_km' => null,
                'price' => null,
                'is_formula' => true,
                'per_km_rate' => 1900,
                'subtract_value' => 7000,
            ],
        ];
    }
};
