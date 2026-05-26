<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class ServiceGrowthChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Growth Layanan Harian';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $trend = $this->analytics()->serviceDailyTrend($this->period());
        $colors = ['#38bdf8', '#22c55e', '#f59e0b', '#ec4899', '#a855f7'];

        return [
            'labels' => $trend['labels'],
            'datasets' => collect($trend['series'])->map(function (array $values, string $code) use (&$colors): array {
                $color = array_shift($colors) ?? '#94a3b8';

                return ['label' => $code, 'data' => $values, 'borderColor' => $color, 'backgroundColor' => $color, 'tension' => 0.3];
            })->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
