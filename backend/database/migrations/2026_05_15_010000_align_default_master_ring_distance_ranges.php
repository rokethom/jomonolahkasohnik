<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ring_pricing_rules')) {
            return;
        }

        $updates = [
            'ring_1' => ['old_min' => 0.0, 'old_max' => 4.0, 'min_km' => 0, 'max_km' => 5],
            'ring_2' => ['old_min' => 4.1, 'old_max' => 9.0, 'min_km' => 5.01, 'max_km' => 10],
            'ring_3' => ['old_min' => 9.1, 'old_max' => null, 'min_km' => 10.01, 'max_km' => null],
        ];

        foreach ($updates as $ring => $range) {
            DB::table('ring_pricing_rules')
                ->where('ring', $ring)
                ->where('min_km', $range['old_min'])
                ->when(
                    $range['old_max'] === null,
                    fn ($query) => $query->whereNull('max_km'),
                    fn ($query) => $query->where('max_km', $range['old_max']),
                )
                ->update([
                    'min_km' => $range['min_km'],
                    'max_km' => $range['max_km'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ring_pricing_rules')) {
            return;
        }

        $updates = [
            'ring_1' => ['old_min' => 0.0, 'old_max' => 5.0, 'min_km' => 0, 'max_km' => 4],
            'ring_2' => ['old_min' => 5.01, 'old_max' => 10.0, 'min_km' => 4.1, 'max_km' => 9],
            'ring_3' => ['old_min' => 10.01, 'old_max' => null, 'min_km' => 9.1, 'max_km' => null],
        ];

        foreach ($updates as $ring => $range) {
            DB::table('ring_pricing_rules')
                ->where('ring', $ring)
                ->where('min_km', $range['old_min'])
                ->when(
                    $range['old_max'] === null,
                    fn ($query) => $query->whereNull('max_km'),
                    fn ($query) => $query->where('max_km', $range['old_max']),
                )
                ->update([
                    'min_km' => $range['min_km'],
                    'max_km' => $range['max_km'],
                    'updated_at' => now(),
                ]);
        }
    }
};
