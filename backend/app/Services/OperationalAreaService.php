<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OperationalAreaService
{
    public function areaIdFromLegacyBranch(?int $branchId): ?int
    {
        if ($branchId === null) {
            return null;
        }

        return Area::query()
            ->where('legacy_branch_id', $branchId)
            ->value('id');
    }

    public function legacyBranchIdFromArea(?int $areaId): ?int
    {
        if ($areaId === null) {
            return null;
        }

        return Area::query()
            ->whereKey($areaId)
            ->value('legacy_branch_id');
    }

    public function branchIdFromArea(?int $areaId): ?int
    {
        if ($areaId === null) {
            return null;
        }

        return Area::query()
            ->whereKey($areaId)
            ->value('branch_id');
    }

    public function resolveAreaId(?int $areaId = null, ?int $branchId = null, ?float $lat = null, ?float $lng = null): ?int
    {
        if ($areaId !== null && Area::query()->whereKey($areaId)->exists()) {
            return $areaId;
        }

        if ($branchId === null) {
            return null;
        }

        $direct = $this->areaIdFromLegacyBranch($branchId);
        if ($direct !== null) {
            return (int) $direct;
        }

        $operationalBranchId = Branch::resolveOperationalAreaId($branchId, $lat, $lng);

        return $operationalBranchId ? $this->areaIdFromLegacyBranch((int) $operationalBranchId) : null;
    }

    public function legacyBranchIdForAreaOrBranch(?int $areaId = null, ?int $branchId = null, ?float $lat = null, ?float $lng = null): ?int
    {
        $areaId = $this->resolveAreaId($areaId, $branchId, $lat, $lng);
        $legacyBranchId = $this->legacyBranchIdFromArea($areaId);

        if ($legacyBranchId !== null) {
            return (int) $legacyBranchId;
        }

        return $branchId !== null ? (Branch::resolveOperationalAreaId($branchId, $lat, $lng) ?? $branchId) : null;
    }

    public function userAreaId(User $user): ?int
    {
        return $this->resolveAreaId($user->area_id, $user->branch_id, (float) $user->lat ?: null, (float) $user->lng ?: null);
    }

    public function whereAreaOrLegacyBranch(Builder $query, ?int $areaId, ?int $legacyBranchId = null): Builder
    {
        return $query->where(function (Builder $query) use ($areaId, $legacyBranchId): void {
            if ($areaId === null && $legacyBranchId === null) {
                $query->whereRaw('1 = 0');
                return;
            }

            if ($areaId !== null) {
                $query->where('area_id', $areaId);
            }

            if ($legacyBranchId !== null) {
                $method = $areaId !== null ? 'orWhere' : 'where';
                $query->{$method}(function (Builder $query) use ($legacyBranchId): void {
                    $query->whereNull('area_id')->where('branch_id', $legacyBranchId);
                });
            }
        });
    }
}
