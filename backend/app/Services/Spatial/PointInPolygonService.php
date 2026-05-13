<?php

declare(strict_types=1);

namespace App\Services\Spatial;

class PointInPolygonService
{
    /**
     * @param array<int, array<int, array{lat: float, lng: float}>> $polygons
     */
    public function contains(float $lat, float $lng, array $polygons): bool
    {
        foreach ($polygons as $polygon) {
            if ($this->containsSinglePolygon($lat, $lng, $polygon)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{lat: float, lng: float}> $polygon
     */
    private function containsSinglePolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $polygon[$i]['lng'];
            $yi = $polygon[$i]['lat'];
            $xj = $polygon[$j]['lng'];
            $yj = $polygon[$j]['lat'];

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < (($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 0.0000001)) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
