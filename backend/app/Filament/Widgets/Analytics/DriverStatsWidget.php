<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DriverStatsWidget extends StatsOverviewWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $data = $this->analytics()->driverSummary($this->period());

        return [
            Stat::make('Driver Online', number_format($data['online_drivers']))->description('Available sekarang')->descriptionIcon('heroicon-m-signal')->color('success'),
            Stat::make('Driver Aktif', number_format($data['active_drivers']))->description('Akun operasional')->descriptionIcon('heroicon-m-identification')->color('primary'),
            Stat::make('Acceptance Rate', $data['acceptance_rate'].'%')->description('Assigned menjadi accepted')->descriptionIcon('heroicon-m-hand-thumb-up')->color('success'),
            Stat::make('Cancellation Rate', $data['cancellation_rate'].'%')->description('Cancelled dari accepted')->descriptionIcon('heroicon-m-x-circle')->color('danger'),
            Stat::make('Driver Rating', number_format($data['average_rating'], 2))->description('Rata-rata rating')->descriptionIcon('heroicon-m-star')->color('warning'),
            Stat::make('Completed Order', number_format($data['completed_orders']))->description('Dalam periode')->descriptionIcon('heroicon-m-check-circle')->color('info'),
        ];
    }
}
