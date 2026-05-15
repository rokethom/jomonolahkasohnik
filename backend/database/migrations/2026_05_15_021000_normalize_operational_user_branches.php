<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'parent_branch_id')) {
            return;
        }

        $operationalRoles = ['customer', 'driver', 'spv', 'operator', 'eksekutor'];
        $parents = DB::table('branches')
            ->whereNull('parent_branch_id')
            ->where(fn ($query) => $query->whereNull('area')->orWhere('area', ''))
            ->get(['id']);

        foreach ($parents as $parent) {
            $children = DB::table('branches')
                ->where('parent_branch_id', $parent->id)
                ->where('is_active', true)
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->orderBy('area')
                ->get(['id', 'latitude', 'longitude']);

            if ($children->isEmpty()) {
                continue;
            }

            DB::table('users')
                ->where('branch_id', $parent->id)
                ->whereIn('role', $operationalRoles)
                ->orderBy('id')
                ->get(['id', 'lat', 'lng'])
                ->each(function (object $user) use ($children): void {
                    $branchId = $this->nearestChildId($children, $user->lat, $user->lng);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'branch_id' => $branchId,
                            'updated_at' => now(),
                        ]);

                    DB::table('user_locations')
                        ->where('user_id', $user->id)
                        ->update([
                            'branch_id' => $branchId,
                            'updated_at' => now(),
                        ]);
                });
        }
    }

    public function down(): void
    {
        // Data normalization is intentionally not reverted.
    }

    private function nearestChildId(Collection $children, mixed $lat, mixed $lng): int
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return (int) $children->first()->id;
        }

        return (int) $children
            ->sortBy(fn (object $branch): float => $this->distanceInMeters((float) $lat, (float) $lng, (float) $branch->latitude, (float) $branch->longitude))
            ->first()
            ->id;
    }

    private function distanceInMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDistance = deg2rad($lat2 - $lat1);
        $lngDistance = deg2rad($lng2 - $lng1);

        $a = sin($latDistance / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDistance / 2) ** 2;

        return 6371000.0 * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
};
