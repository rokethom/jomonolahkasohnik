<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PriceSetting;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderPricingOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Orders', Order::query()->count())
                ->description('Semua order Jojo App')
                ->icon('heroicon-o-shopping-bag')
                ->color('primary'),
            Stat::make('Active Orders', Order::query()
                ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
                ->count())
                ->description('Belum completed/cancelled')
                ->icon('heroicon-o-truck')
                ->color('warning'),
            Stat::make('Pricing Rules', PriceSetting::query()->count())
                ->description('Aturan tarif aktif di database')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
        ];
    }
}
