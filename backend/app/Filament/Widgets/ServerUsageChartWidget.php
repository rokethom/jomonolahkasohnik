<?php

namespace App\Filament\Widgets;

use App\Services\AdminDashboardMetricsService;
use Filament\Widgets\ChartWidget;

class ServerUsageChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Server Usage Trend';

    protected static ?string $description = 'CPU, RAM, dan storage usage.';

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $series = app(AdminDashboardMetricsService::class)->chartSeries();

        return [
            'datasets' => [
                [
                    'label' => 'CPU Usage',
                    'data' => $series['cpu'],
                    'borderColor' => '#60a5fa',
                    'backgroundColor' => 'rgba(96, 165, 250, 0.18)',
                    'tension' => 0.36,
                ],
                [
                    'label' => 'RAM Usage',
                    'data' => $series['ram'],
                    'borderColor' => '#a78bfa',
                    'backgroundColor' => 'rgba(167, 139, 250, 0.18)',
                    'tension' => 0.36,
                ],
                [
                    'label' => 'Storage Usage',
                    'data' => $series['storage'],
                    'borderColor' => '#fb7185',
                    'backgroundColor' => 'rgba(251, 113, 133, 0.18)',
                    'tension' => 0.36,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
