<?php

namespace App\Filament\Widgets;

use App\Services\AdminDashboardMetricsService;
use Filament\Widgets\ChartWidget;

class OperationsChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Operations Trend';

    protected static ?string $description = 'Order per jam, revenue, active driver, dan API latency.';

    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $series = app(AdminDashboardMetricsService::class)->chartSeries();

        return [
            'datasets' => [
                [
                    'label' => 'Order per jam',
                    'data' => $series['orders'],
                    'borderColor' => '#38bdf8',
                    'backgroundColor' => 'rgba(56, 189, 248, 0.16)',
                    'tension' => 0.36,
                ],
                [
                    'label' => 'Revenue trend',
                    'data' => array_map(fn (int $value): int => (int) round($value / 1000), $series['revenue']),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.14)',
                    'tension' => 0.36,
                ],
                [
                    'label' => 'Active driver trend',
                    'data' => $series['active_drivers'],
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.14)',
                    'tension' => 0.36,
                ],
                [
                    'label' => 'API latency ms',
                    'data' => $series['latency'],
                    'borderColor' => '#f43f5e',
                    'backgroundColor' => 'rgba(244, 63, 94, 0.14)',
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
