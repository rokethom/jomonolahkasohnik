<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\AnalyticsPage;
use App\Filament\Widgets\Analytics\DriverRankingTableWidget;
use App\Filament\Widgets\Analytics\DriverStatsWidget;

class DriverAnalytics extends AnalyticsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Driver Analytics';

    protected static ?string $title = 'Driver Analytics';

    protected static ?string $slug = 'analytics/drivers';

    protected static ?int $navigationSort = 4;

    public function getWidgets(): array
    {
        return [
            DriverStatsWidget::class,
            DriverRankingTableWidget::class,
        ];
    }
}
