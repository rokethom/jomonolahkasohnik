<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class DriverRankingTableWidget extends TableWidget
{
    use UsesAnalyticsFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ranking Driver')
            ->query($this->analytics()->driverRankingQuery($this->period()))
            ->columns([
                Tables\Columns\TextColumn::make('driver.user.username')->label('Driver')->searchable(),
                Tables\Columns\TextColumn::make('completed_orders')->label('Completed')->numeric(),
                Tables\Columns\TextColumn::make('accepted_orders')->label('Accepted')->numeric(),
                Tables\Columns\TextColumn::make('cancelled_orders')->label('Cancelled')->numeric(),
                Tables\Columns\TextColumn::make('gross_revenue')->label('Revenue')->money('IDR'),
                Tables\Columns\TextColumn::make('rating_sum')
                    ->label('Rating')
                    ->state(fn ($record): float => $record->ratings_count > 0 ? round($record->rating_sum / $record->ratings_count, 2) : 0),
                Tables\Columns\TextColumn::make('acceptance_rate')
                    ->label('Acceptance')
                    ->state(fn ($record): string => $record->assigned_orders > 0 ? round(($record->accepted_orders / $record->assigned_orders) * 100, 1).'%' : '0%'),
            ])
            ->paginated([10]);
    }
}
