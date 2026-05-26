<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class ServicePopularityChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Layanan Terpopuler';

    protected static ?string $pollingInterval = '60s';

    protected function getData(): array
    {
        $rows = $this->analytics()->serviceTotals($this->period())->take(8);

        return [
            'labels' => $rows->pluck('service_code')->all(),
            'datasets' => [[
                'label' => 'Order',
                'data' => $rows->pluck('orders_count')->all(),
                'backgroundColor' => ['#38bdf8', '#22c55e', '#f59e0b', '#f97316', '#ec4899', '#14b8a6', '#a855f7', '#ef4444'],
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
