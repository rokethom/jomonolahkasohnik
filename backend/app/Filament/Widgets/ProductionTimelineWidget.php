<?php

namespace App\Filament\Widgets;

use App\Services\AdminDashboardMetricsService;
use Filament\Widgets\Widget;

class ProductionTimelineWidget extends Widget
{
    protected static string $view = 'filament.widgets.production-timeline';

    protected static ?int $sort = 6;

    protected static ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return [
            'items' => app(AdminDashboardMetricsService::class)->timeline(),
        ];
    }
}
