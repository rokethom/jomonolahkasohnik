<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\ChartWidget;

class PeakHourChartWidget extends ChartWidget
{
    use UsesAnalyticsFilters;

    protected static ?string $heading = 'Distribusi Order per Jam';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $hours = $this->analytics()->peakHours($this->period())['hours'];

        return [
            'labels' => array_map(fn (int $hour): string => sprintf('%02d:00', $hour), array_keys($hours)),
            'datasets' => [[
                'label' => 'Jumlah order',
                'data' => array_values($hours),
                'backgroundColor' => '#38bdf8',
                'borderColor' => '#0284c7',
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
