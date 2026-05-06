<?php

namespace App\Filament\Widgets;

use App\Services\AdminDashboardMetricsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModernStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $stats = app(AdminDashboardMetricsService::class)->productionStats();

        return [
            Stat::make('Total Driver Online', number_format($stats['total_driver_online']))
                ->description('Driver available sekarang')
                ->descriptionIcon('heroicon-m-truck')
                ->color('success')
                ->chart([4, 7, 9, 8, 12, 14, 13]),
            Stat::make('Total Order Hari Ini', number_format($stats['total_order_today']))
                ->description('Semua order dibuat hari ini')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('info')
                ->chart([2, 3, 4, 7, 9, 12, 15]),
            Stat::make('Pending Order', number_format($stats['pending_order']))
                ->description('Created/searching/cancel review')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->chart([5, 4, 6, 3, 5, 2, 4]),
            Stat::make('Success Order', number_format($stats['success_order']))
                ->description('Completed hari ini')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart([1, 3, 4, 6, 8, 10, 11]),
            Stat::make('Failed Order', number_format($stats['failed_order']))
                ->description('Cancelled hari ini')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color('danger')
                ->chart([0, 1, 1, 0, 2, 1, 1]),
            Stat::make('Active Customer', number_format($stats['active_customer']))
                ->description('Customer aktif')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart([8, 9, 10, 12, 15, 15, 18]),
            Stat::make('Active Driver', number_format($stats['active_driver']))
                ->description('Akun driver aktif')
                ->descriptionIcon('heroicon-m-identification')
                ->color('success')
                ->chart([6, 7, 8, 9, 10, 12, 12]),
            Stat::make('Revenue Hari Ini', 'Rp '.number_format($stats['revenue_today'], 0, ',', '.'))
                ->description('Dari order completed')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart([10, 12, 9, 14, 18, 22, 25]),
            Stat::make('Server Status', strtoupper($stats['server_status']))
                ->description('Database dan storage')
                ->descriptionIcon('heroicon-m-server')
                ->color($this->statusColor($stats['server_status'])),
            Stat::make('Queue Status', strtoupper($stats['queue_status']))
                ->description(config('queue.default').' queue')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($this->statusColor($stats['queue_status'])),
            Stat::make('WebSocket Status', strtoupper($stats['websocket_status']))
                ->description(config('broadcasting.default').' broadcast')
                ->descriptionIcon('heroicon-m-signal')
                ->color($this->statusColor($stats['websocket_status'])),
        ];
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'critical' => 'danger',
            'warning' => 'warning',
            default => 'success',
        };
    }
}
