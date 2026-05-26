<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ServiceAnalyticsTableWidget extends TableWidget
{
    use UsesAnalyticsFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ringkasan Layanan')
            ->query($this->analytics()->serviceQuery($this->period()))
            ->columns([
                Tables\Columns\TextColumn::make('service_code')->label('Layanan')->badge()->searchable(),
                Tables\Columns\TextColumn::make('orders_count')->label('Total order')->numeric(),
                Tables\Columns\TextColumn::make('completed_orders')->label('Completed')->numeric(),
                Tables\Columns\TextColumn::make('cancelled_orders')->label('Cancelled')->numeric(),
                Tables\Columns\TextColumn::make('gross_revenue')->label('Revenue')->money('IDR'),
                Tables\Columns\TextColumn::make('rating_sum')
                    ->label('Avg rating')
                    ->state(fn ($record): float => $record->ratings_count > 0 ? round($record->rating_sum / $record->ratings_count, 2) : 0),
            ])
            ->paginated([10]);
    }
}
