<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Widgets\Analytics\OverviewStatsWidget;
use App\Filament\Widgets\Analytics\RevenueTrendChartWidget;
use App\Filament\Widgets\Analytics\ServicePopularityChartWidget;

class AnalyticsOverview extends AnalyticsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Analytics Overview';

    protected static ?string $slug = 'analytics/overview';

    protected static ?int $navigationSort = 1;

    public function getWidgets(): array
    {
        return [
            OverviewStatsWidget::class,
            RevenueTrendChartWidget::class,
            ServicePopularityChartWidget::class,
        ];
    }
}
