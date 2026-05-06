<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
    }
}
