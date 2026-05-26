<?php

namespace App\Filament\Widgets\Analytics\Concerns;

use App\Repositories\AnalyticsRepository;
use App\Support\AnalyticsAccess;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait UsesAnalyticsFilters
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return AnalyticsAccess::allowed();
    }

    protected function analytics(): AnalyticsRepository
    {
        return app(AnalyticsRepository::class);
    }

    protected function period(): AnalyticsPeriod
    {
        return AnalyticsPeriod::fromFilters($this->filters);
    }

    protected function rupiah(int|float $amount): string
    {
        return 'Rp '.number_format((int) $amount, 0, ',', '.');
    }

    public function updatedFilters(): void
    {
        if (property_exists($this, 'cachedData')) {
            $this->cachedData = null;
        }

        if (property_exists($this, 'cachedStats')) {
            $this->cachedStats = null;
        }
    }
}
