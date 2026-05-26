<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Widgets\Analytics\ServiceAnalyticsTableWidget;
use App\Filament\Widgets\Analytics\ServiceGrowthChartWidget;
use App\Filament\Widgets\Analytics\ServiceOrdersChartWidget;
use App\Filament\Widgets\Analytics\ServicePopularityChartWidget;
use App\Filament\Widgets\Analytics\ServiceRevenueChartWidget;

class ServiceAnalytics extends AnalyticsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Service Analytics';

    protected static ?string $title = 'Service Analytics';

    protected static ?string $slug = 'analytics/services';

    protected static ?int $navigationSort = 2;

    public function getWidgets(): array
    {
        return [
            ServiceOrdersChartWidget::class,
            ServicePopularityChartWidget::class,
            ServiceRevenueChartWidget::class,
            ServiceGrowthChartWidget::class,
            ServiceAnalyticsTableWidget::class,
        ];
    }
}
