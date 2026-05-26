<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Widgets\Analytics\AreaAnalyticsTableWidget;
use App\Filament\Widgets\Analytics\AreaStatsWidget;
use App\Filament\Widgets\Analytics\TopAreasChartWidget;

class AreaAnalytics extends AnalyticsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Area Analytics';

    protected static ?string $title = 'Area Analytics';

    protected static ?string $slug = 'analytics/areas';

    protected static ?int $navigationSort = 5;

    public function getWidgets(): array
    {
        return [
            AreaStatsWidget::class,
            TopAreasChartWidget::class,
            AreaAnalyticsTableWidget::class,
        ];
    }
}
