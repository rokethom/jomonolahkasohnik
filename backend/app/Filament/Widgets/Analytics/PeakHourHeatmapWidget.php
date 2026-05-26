<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Widgets\Widget;

class PeakHourHeatmapWidget extends Widget
{
    use UsesAnalyticsFilters;

    protected static string $view = 'filament.widgets.analytics.peak-hour-heatmap';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $daily = $this->analytics()->peakHours($this->period())['daily'];
        $max = collect($daily)->flatten()->max() ?: 1;

        return ['daily' => $daily, 'max' => $max];
    }
}
