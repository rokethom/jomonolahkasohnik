<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Branch;

interface BranchRepositoryInterface
{
    public function nearestActive(float $lat, float $lng): ?Branch;
}
