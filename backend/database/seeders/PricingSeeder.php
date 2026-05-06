<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\PriceSetting;
use Illuminate\Database\Seeder;

class PricingSeeder extends Seeder
{
    public function run(): void
    {
        $branches = collect([
            ['name' => 'Cabang A', 'area' => 'Asembagus', 'latitude' => -7.75086000, 'longitude' => 114.21561000],
            ['name' => 'Cabang B', 'area' => 'Jember', 'latitude' => -8.17235700, 'longitude' => 113.70030200],
        ])->map(fn (array $branch) => Branch::query()->updateOrCreate(
            ['name' => $branch['name']],
            $branch,
        ));

        $branches->each(function (Branch $branch): void {
            $rules = [
                ['name' => $branch->name.' 0-5 KM', 'min_km' => 0, 'max_km' => 5, 'price' => 6000, 'is_formula' => false],
                ['name' => $branch->name.' 5-10 KM', 'min_km' => 5, 'max_km' => 10, 'price' => 12000, 'is_formula' => false],
                [
                    'name' => $branch->name.' >10 KM',
                    'min_km' => 10,
                    'max_km' => null,
                    'price' => null,
                    'is_formula' => true,
                    'per_km_rate' => 1900,
                    'subtract_value' => 7000,
                ],
            ];

            foreach ($rules as $rule) {
                PriceSetting::query()->updateOrCreate(
                    ['name' => $rule['name'], 'branch_id' => $branch->id],
                    [
                        'branch_id' => $branch->id,
                        'per_km_rate' => $rule['per_km_rate'] ?? null,
                        'subtract_value' => $rule['subtract_value'] ?? 0,
                        ...$rule,
                    ],
                );
            }
        });
    }
}
