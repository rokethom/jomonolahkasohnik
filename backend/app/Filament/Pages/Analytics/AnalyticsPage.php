<?php

namespace App\Filament\Pages\Analytics;

use App\Support\AnalyticsAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

abstract class AnalyticsPage extends Page
{
    use HasFiltersForm;

    protected static ?string $navigationGroup = 'Analytics';

    protected static string $view = 'filament.pages.analytics.dashboard';

    protected static ?string $pollingInterval = '60s';

    public static function canAccess(): bool
    {
        return AnalyticsAccess::allowed();
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('period')
                    ->label('Periode')
                    ->options([
                        'today' => 'Hari ini',
                        '7_days' => '7 hari',
                        '30_days' => '30 hari',
                        'custom' => 'Rentang khusus',
                    ])
                    ->default('today')
                    ->native(false)
                    ->live(),
                Forms\Components\DatePicker::make('from')
                    ->label('Mulai')
                    ->default(now()->toDateString())
                    ->visible(fn (Forms\Get $get): bool => $get('period') === 'custom')
                    ->live(),
                Forms\Components\DatePicker::make('to')
                    ->label('Sampai')
                    ->default(now()->toDateString())
                    ->visible(fn (Forms\Get $get): bool => $get('period') === 'custom')
                    ->live(),
            ])
            ->columns(['default' => 1, 'md' => 3]);
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    abstract public function getWidgets(): array;

    public function getVisibleWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getWidgets());
    }

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 2];
    }

    public function getWidgetData(): array
    {
        return ['filters' => $this->filters];
    }

    public function getFiltersSessionKey(): string
    {
        return 'analytics_global_filters';
    }
}
