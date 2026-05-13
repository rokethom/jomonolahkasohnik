<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Area;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Area> */
class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::query()->value('id') ?? Branch::query()->create(['name' => 'Situbondo', 'area' => 'Kota', 'latitude' => -7.7, 'longitude' => 114])->id,
            'name' => $this->faker->city(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'is_active' => true,
        ];
    }
}
