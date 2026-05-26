<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Widgets\Analytics\RevenueComparisonChartWidget;
use App\Filament\Widgets\Analytics\RevenueStatsWidget;
use App\Filament\Widgets\Analytics\RevenueTrendChartWidget;

class RevenueAnalytics extends AnalyticsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Revenue Analytics';

    protected static ?string $title = 'Revenue Analytics';

    protected static ?string $slug = 'analytics/revenue';

    protected static ?int $navigationSort = 3;

    public function getWidgets(): array
    {
        return [
            RevenueStatsWidget::class,
            RevenueTrendChartWidget::class,
            RevenueComparisonChartWidget::class,
        ];
    }
}
