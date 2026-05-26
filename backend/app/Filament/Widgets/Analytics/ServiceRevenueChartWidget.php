<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class ServiceRevenueChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Revenue per Layanan';

    protected function getData(): array
    {
        $rows = $this->analytics()->serviceTotals($this->period());

        return [
            'labels' => $rows->pluck('service_code')->all(),
            'datasets' => [[
                'label' => 'Gross revenue (Rp ribu)',
                'data' => $rows->map(fn ($row): int => (int) round($row->gross_revenue / 1000))->all(),
                'backgroundColor' => '#10b981',
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
