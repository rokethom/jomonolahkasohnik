<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class RevenueComparisonChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Revenue Harian, Mingguan, Bulanan & Tahunan';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $totals = $this->analytics()->revenueComparison();

        return [
            'labels' => array_keys($totals),
            'datasets' => [[
                'label' => 'Gross Revenue (Rp ribu)',
                'data' => array_map(fn (int $value): int => (int) round($value / 1000), array_values($totals)),
                'backgroundColor' => ['#38bdf8', '#14b8a6', '#22c55e', '#f59e0b'],
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
