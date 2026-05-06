<?php

namespace App\Filament\Widgets;

use App\Services\AdminDashboardMetricsService;
use Filament\Widgets\Widget;

class ServerMonitoringWidget extends Widget
{
    protected static string $view = 'filament.widgets.server-monitoring';

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $service = app(AdminDashboardMetricsService::class);

        return [
            'metrics' => $service->serverMetrics(),
            'endpoints' => $service->endpointHealth(),
        ];
    }
}
