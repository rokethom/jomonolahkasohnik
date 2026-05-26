<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class RevenueTrendChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Revenue Harian';

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $rows = $this->analytics()->dailyRevenue($this->period());

        return [
            'labels' => $rows->map(fn ($row): string => $row->analytics_date->format('d M'))->all(),
            'datasets' => [
                ['label' => 'Gross (Rp ribu)', 'data' => $rows->map(fn ($row): int => (int) round($row->gross_revenue / 1000))->all(), 'borderColor' => '#22c55e', 'backgroundColor' => 'rgba(34,197,94,.15)', 'tension' => 0.3],
                ['label' => 'Platform fee (Rp ribu)', 'data' => $rows->map(fn ($row): int => (int) round($row->platform_fee / 1000))->all(), 'borderColor' => '#38bdf8', 'backgroundColor' => 'rgba(56,189,248,.15)', 'tension' => 0.3],
                ['label' => 'Driver payout (Rp ribu)', 'data' => $rows->map(fn ($row): int => (int) round($row->driver_payout / 1000))->all(), 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245,158,11,.15)', 'tension' => 0.3],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
