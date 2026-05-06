<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasActiveDateRange
{
    public function scopeActiveInRange(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }
}
