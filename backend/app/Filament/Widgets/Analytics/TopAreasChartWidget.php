<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class TopAreasChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Top 10 Area';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $rows = $this->analytics()->areaTotals($this->period());

        return [
            'labels' => $rows->pluck('area_name')->all(),
            'datasets' => [
                ['label' => 'Total Order', 'data' => $rows->pluck('orders_count')->all(), 'backgroundColor' => '#38bdf8'],
                ['label' => 'Revenue (Rp ribu)', 'data' => $rows->map(fn ($row): int => (int) round($row->gross_revenue / 1000))->all(), 'backgroundColor' => '#22c55e'],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
