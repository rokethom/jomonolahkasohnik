<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverviewStatsWidget extends StatsOverviewWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $data = $this->analytics()->overview($this->period());

        return [
            $this->trendStat('Total Orders', $data['orders_count'], $data['orders_trend'], 'heroicon-m-shopping-bag', 'info'),
            $this->trendStat('Total Revenue', $this->rupiah($data['gross_revenue']), $data['revenue_trend'], 'heroicon-m-banknotes', 'success'),
            Stat::make('Total Active Drivers', number_format($data['active_drivers']))->description('Akun siap operasional')->descriptionIcon('heroicon-m-identification')->color('primary'),
            Stat::make('Total Online Drivers', number_format($data['online_drivers']))->description('Driver available sekarang')->descriptionIcon('heroicon-m-signal')->color('success'),
            $this->trendStat('Completed Orders', $data['completed_orders'], $data['completed_trend'], 'heroicon-m-check-circle', 'success'),
            $this->trendStat('Cancelled Orders', $data['cancelled_orders'], $data['cancelled_trend'], 'heroicon-m-x-circle', 'danger'),
            Stat::make('Average Rating', number_format($data['average_rating'], 2))->description('Dari rating periode dipilih')->descriptionIcon('heroicon-m-star')->color('warning'),
            Stat::make('Repeat Customers', number_format($data['repeat_customers']))->description('Customer order > 1 per hari')->descriptionIcon('heroicon-m-arrow-path')->color('primary'),
        ];
    }

    private function trendStat(string $label, int|string $value, float $trend, string $icon, string $color): Stat
    {
        $direction = $trend >= 0 ? 'naik' : 'turun';
        $trendColor = $trend < 0 && $label !== 'Cancelled Orders' ? 'danger' : $color;

        return Stat::make($label, is_int($value) ? number_format($value) : $value)
            ->description(abs($trend).'% '.$direction.' vs periode sebelumnya')
            ->descriptionIcon($trend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->color($trendColor)
            ->chart([$value === 0 ? 0 : 1, 2, 2, 3, 4, 4, 5]);
    }
}
