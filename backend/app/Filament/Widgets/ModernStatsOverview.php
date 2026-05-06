<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModernStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Total User', User::query()->count())
                ->description('Semua akun Jojo App')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary')
                ->chart([2, 5, 8, 13, 21, 34]),
            Stat::make('Total Driver', User::query()->where('role', UserRole::Driver->value)->count())
                ->description('Driver terdaftar')
                ->descriptionIcon('heroicon-m-truck')
                ->color('success')
                ->chart([1, 3, 6, 8, 10, 13]),
            Stat::make('Active Order', Order::query()
                ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
                ->count())
                ->description('Order sedang berjalan')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('warning')
                ->chart([4, 6, 5, 9, 12, 10]),
            Stat::make('Suspended Driver', User::query()
                ->where('role', UserRole::Driver->value)
                ->where('is_suspended', true)
                ->count())
                ->description('Driver diblokir sementara')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color('danger')
                ->chart([1, 1, 2, 1, 3, 2]),
        ];
    }
}
