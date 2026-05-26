<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Widgets\Analytics\PeakHourChartWidget;
use App\Filament\Widgets\Analytics\PeakHourHeatmapWidget;
use App\Filament\Widgets\Analytics\PeakHourStatsWidget;

class PeakHourAnalytics extends AnalyticsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Peak Hour Analytics';

    protected static ?string $title = 'Peak Hour Analytics';

    protected static ?string $slug = 'analytics/peak-hours';

    protected static ?int $navigationSort = 6;

    public function getWidgets(): array
    {
        return [
            PeakHourStatsWidget::class,
            PeakHourChartWidget::class,
            PeakHourHeatmapWidget::class,
        ];
    }
}
