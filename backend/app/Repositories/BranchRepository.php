<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\BranchRepositoryInterface;
use App\Models\Branch;
use App\Services\Distance\DistanceService;

class BranchRepository implements BranchRepositoryInterface
{
    public function __construct(private readonly DistanceService $distance)
    {
    }

    public function nearestActive(float $lat, float $lng): ?Branch
    {
        return Branch::query()
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('branches', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->get()
            ->sortBy(fn (Branch $branch): float => $this->distance->calculateDistanceKm(
                $lat,
                $lng,
                (float) $branch->latitude,
                (float) $branch->longitude,
            ))
            ->first();
    }
}
