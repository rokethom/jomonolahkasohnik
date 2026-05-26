<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueStatsWidget extends StatsOverviewWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $data = $this->analytics()->overview($this->period());

        return [
            Stat::make('Gross Revenue', $this->rupiah($data['gross_revenue']))->description('Total transaksi selesai')->descriptionIcon('heroicon-m-banknotes')->color('success'),
            Stat::make('Net Revenue', $this->rupiah($data['net_revenue']))->description('Pendapatan platform')->descriptionIcon('heroicon-m-building-library')->color('primary'),
            Stat::make('Driver Payout', $this->rupiah($data['driver_payout']))->description('Nilai diterima driver')->descriptionIcon('heroicon-m-truck')->color('info'),
            Stat::make('Platform Fee', $this->rupiah($data['platform_fee']))->description('Service charge completed')->descriptionIcon('heroicon-m-receipt-percent')->color('warning'),
        ];
    }
}
