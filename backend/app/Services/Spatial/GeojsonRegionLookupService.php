<?php

namespace App\Services\Spatial;

use App\Models\GeojsonRegion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class GeojsonRegionLookupService
{
    public function detect(float $lat, float $lng): ?GeojsonRegion
    {
        if (! Schema::hasTable('geojson_regions')) {
            return null;
        }

        $cacheKey = 'geojson-region-lookup:'.round($lat, 4).':'.round($lng, 4);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($lat, $lng): ?GeojsonRegion {
            return GeojsonRegion::query()
                ->with(['branch', 'area'])
                ->active()
                ->containingBoundingBox($lat, $lng)
                ->orderByDesc('version')
                ->get()
                ->first(fn (GeojsonRegion $region): bool => $this->contains($lat, $lng, $region->coordinates ?? []));
        });
    }

    private function contains(float $lat, float $lng, array $polygons): bool
    {
        foreach ($polygons as $polygon) {
            if (is_array($polygon) && $this->containsSinglePolygon($lat, $lng, $polygon)) {
                return true;
            }
        }

        return false;
    }

    private function containsSinglePolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($polygon[$i]['lng'] ?? 0);
            $yi = (float) ($polygon[$i]['lat'] ?? 0);
            $xj = (float) ($polygon[$j]['lng'] ?? 0);
            $yj = (float) ($polygon[$j]['lat'] ?? 0);

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 0.0000001)) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
