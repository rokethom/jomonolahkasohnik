<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $areaOwnerTables = [
        'users',
        'orders',
        'geofence_areas',
        'location_logs',
        'user_locations',
        'chat_conversations',
        'ring_pricing_rules',
        'price_settings',
        'zone_pricing_rules',
        'geojson_regions',
        'location_pois',
        'ai_alias_maps',
        'ai_location_suggestions',
        'ring_pricing_suggestions',
    ];

    public function up(): void
    {
        $this->extendAreasTable();
        $this->addAreaIdColumns();
        $this->syncAreasFromLegacyOperationalBranches();
        $this->backfillAreaOwners();
    }

    public function down(): void
    {
        // Keep canonical area data. Dropping it can detach production users, orders,
        // pricing, and GeoJSON records from their operational area.
    }

    private function extendAreasTable(): void
    {
        Schema::table('areas', function (Blueprint $table): void {
            if (! Schema::hasColumn('areas', 'legacy_branch_id')) {
                $table->foreignId('legacy_branch_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained('branches')
                    ->nullOnDelete();

                $table->unique('legacy_branch_id', 'areas_legacy_branch_id_unique');
            }

            if (! Schema::hasColumn('areas', 'latitude')) {
                $table->decimal('latitude', 11, 8)->nullable()->after('code');
            }

            if (! Schema::hasColumn('areas', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }

            if (! Schema::hasColumn('areas', 'radius_km')) {
                $table->decimal('radius_km', 8, 2)->nullable()->after('longitude');
            }

            if (! Schema::hasColumn('areas', 'pricing_origin_name')) {
                $table->string('pricing_origin_name')->nullable()->after('radius_km');
            }

            if (! Schema::hasColumn('areas', 'pricing_origin_latitude')) {
                $table->decimal('pricing_origin_latitude', 11, 8)->nullable()->after('pricing_origin_name');
            }

            if (! Schema::hasColumn('areas', 'pricing_origin_longitude')) {
                $table->decimal('pricing_origin_longitude', 11, 8)->nullable()->after('pricing_origin_latitude');
            }
        });
    }

    private function addAreaIdColumns(): void
    {
        foreach ($this->areaOwnerTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'area_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $after = Schema::hasColumn($tableName, 'branch_id') ? 'branch_id' : 'id';

                $table->foreignId('area_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('areas')
                    ->nullOnDelete();

                $table->index(['area_id']);
            });
        }
    }

    private function syncAreasFromLegacyOperationalBranches(): void
    {
        $children = DB::table('branches')
            ->whereNotNull('parent_branch_id')
            ->orderBy('id')
            ->get();

        foreach ($children as $child) {
            $payload = [
                    'branch_id' => $child->parent_branch_id,
                    'name' => $child->area ?: $child->name,
                    'code' => $child->branch_code ?: 'AREA-'.$child->id,
                    'latitude' => $child->latitude,
                    'longitude' => $child->longitude,
                    'radius_km' => $child->radius_km,
                    'pricing_origin_name' => $child->pricing_origin_name,
                    'pricing_origin_latitude' => $child->pricing_origin_latitude,
                    'pricing_origin_longitude' => $child->pricing_origin_longitude,
                    'is_active' => (bool) $child->is_active,
                    'updated_at' => now(),
                ];

            if (DB::table('areas')->where('legacy_branch_id', $child->id)->exists()) {
                DB::table('areas')->where('legacy_branch_id', $child->id)->update($payload);
            } else {
                DB::table('areas')->insert([
                    ...$payload,
                    'legacy_branch_id' => $child->id,
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function backfillAreaOwners(): void
    {
        $areas = DB::table('areas')
            ->whereNotNull('legacy_branch_id')
            ->get(['id', 'legacy_branch_id']);

        foreach ($areas as $area) {
            foreach ($this->areaOwnerTables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id') || ! Schema::hasColumn($table, 'area_id')) {
                    continue;
                }

                DB::table($table)
                    ->where('branch_id', $area->legacy_branch_id)
                    ->whereNull('area_id')
                    ->update(['area_id' => $area->id]);
            }
        }
    }
};
