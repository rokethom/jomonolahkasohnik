<?php

use App\Services\Geojson\GeojsonParserService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geojson_regions')) {
            return;
        }

        $parser = app(GeojsonParserService::class);

        DB::table('geojson_regions')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->each(function (object $region) use ($parser): void {
                $geojson = json_decode((string) $region->geojson, true);
                if (! is_array($geojson) || ($geojson['type'] ?? null) !== 'FeatureCollection') {
                    return;
                }

                $rows = $parser->parseRows($geojson);
                if (count($rows) <= 1) {
                    return;
                }

                foreach ($rows as $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }

                    $exists = DB::table('geojson_regions')
                        ->where('branch_id', $region->branch_id)
                        ->where('name', $name)
                        ->where('version', $region->version)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    unset($row['name']);

                    DB::table('geojson_regions')->insert([
                        'branch_id' => $region->branch_id,
                        'area_id' => $region->area_id,
                        'name' => Str::limit($name, 255, ''),
                        'geojson' => json_encode($row['geojson']),
                        'geometry_type' => $row['geometry_type'],
                        'coordinates' => json_encode($row['coordinates']),
                        'centroid_lat' => $row['centroid_lat'],
                        'centroid_lng' => $row['centroid_lng'],
                        'min_lat' => $row['min_lat'],
                        'max_lat' => $row['max_lat'],
                        'min_lng' => $row['min_lng'],
                        'max_lng' => $row['max_lng'],
                        'version' => $region->version,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        Cache::flush();
    }
};
