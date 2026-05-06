<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $branches = [
            ['name' => 'Cabang A', 'area' => 'Asembagus', 'latitude' => -7.75086000, 'longitude' => 114.21561000],
            ['name' => 'Cabang B', 'area' => 'Jember', 'latitude' => -8.17235700, 'longitude' => 113.70030200],
        ];

        foreach ($branches as $branch) {
            DB::table('branches')->updateOrInsert(
                ['name' => $branch['name']],
                [...$branch, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        DB::table('branches')
            ->whereIn('name', array_column($branches, 'name'))
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($branch) use ($now): void {
                $rules = [
                    [
                        'name' => $branch->name.' 0-5 KM',
                        'branch_id' => $branch->id,
                        'min_km' => 0,
                        'max_km' => 5,
                        'price' => 6000,
                        'is_formula' => false,
                        'per_km_rate' => null,
                        'subtract_value' => 0,
                    ],
                    [
                        'name' => $branch->name.' 5-10 KM',
                        'branch_id' => $branch->id,
                        'min_km' => 5,
                        'max_km' => 10,
                        'price' => 12000,
                        'is_formula' => false,
                        'per_km_rate' => null,
                        'subtract_value' => 0,
                    ],
                    [
                        'name' => $branch->name.' >10 KM',
                        'branch_id' => $branch->id,
                        'min_km' => 10,
                        'max_km' => null,
                        'price' => null,
                        'is_formula' => true,
                        'per_km_rate' => 1900,
                        'subtract_value' => 7000,
                    ],
                ];

                foreach ($rules as $rule) {
                    DB::table('price_settings')->updateOrInsert(
                        ['name' => $rule['name'], 'branch_id' => $rule['branch_id']],
                        [...$rule, 'created_at' => $now, 'updated_at' => $now],
                    );
                }
            });
    }

    public function down(): void
    {
        DB::table('price_settings')->whereIn('name', [
            'Cabang A 0-5 KM',
            'Cabang A 5-10 KM',
            'Cabang A >10 KM',
            'Cabang B 0-5 KM',
            'Cabang B 5-10 KM',
            'Cabang B >10 KM',
        ])->delete();

        DB::table('branches')->whereIn('name', ['Cabang A', 'Cabang B'])->delete();
    }
};
