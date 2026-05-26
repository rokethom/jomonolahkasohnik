<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AreaStatsWidget extends StatsOverviewWidget
{
    use UsesAnalyticsFilters;

    protected function getStats(): array
    {
        $areas = $this->analytics()->areaTotals($this->period());
        $top = $areas->first();

        return [
            Stat::make('Area Paling Ramai', $top?->area_name ?? '-')
                ->description(number_format((int) ($top?->orders_count ?? 0)).' order')
                ->descriptionIcon('heroicon-m-map-pin')
                ->color('primary'),
            Stat::make('Revenue Area Teratas', $this->rupiah((int) ($top?->gross_revenue ?? 0)))
                ->description('Revenue completed')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('Area Terpantau', number_format($areas->count()))
                ->description('Top 10 pada chart')
                ->descriptionIcon('heroicon-m-map')
                ->color('info'),
        ];
    }
}
