<?php

declare(strict_types=1);

namespace App\DTOs\Pricing;

use App\Models\Area;
use App\Models\Branch;
use App\Models\GeojsonRegion;

final readonly class SpatialDetectionData
{
    public function __construct(
        public ?Branch $branch,
        public ?Area $area,
        public ?GeojsonRegion $region,
        public string $source,
    ) {
    }
}
