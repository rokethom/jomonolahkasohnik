<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PeakHourStatsWidget extends StatsOverviewWidget
{
    use UsesAnalyticsFilters;

    protected function getStats(): array
    {
        $peak = $this->analytics()->peakHours($this->period());

        return collect($peak['top'])->values()->map(function (int $orders, int $index) use ($peak): Stat {
            $hour = array_keys($peak['top'])[$index];
            $label = sprintf('%02d:00-%02d:00', $hour, ($hour + 1) % 24);

            return Stat::make('Peak Hour '.($index + 1), $label)
                ->description(number_format($orders).' order')
                ->descriptionIcon('heroicon-m-clock')
                ->color($index === 0 ? 'warning' : 'info');
        })->all();
    }
}
