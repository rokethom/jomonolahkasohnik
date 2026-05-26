<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class ServiceOrdersChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Order per Layanan';

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $rows = $this->analytics()->serviceTotals($this->period());

        return [
            'labels' => $rows->pluck('service_code')->all(),
            'datasets' => [
                ['label' => 'Total Order', 'data' => $rows->pluck('orders_count')->all(), 'backgroundColor' => '#38bdf8'],
                ['label' => 'Completed', 'data' => $rows->pluck('completed_orders')->all(), 'backgroundColor' => '#22c55e'],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
