<?php

namespace App\Filament\Widgets\Analytics;

use App\Filament\Widgets\Analytics\Concerns\UsesAnalyticsFilters;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class AreaAnalyticsTableWidget extends TableWidget
{
    use UsesAnalyticsFilters;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Performa Area')
            ->query($this->analytics()->areaQuery($this->period()))
            ->columns([
                Tables\Columns\TextColumn::make('area_name')->label('Area')->searchable(),
                Tables\Columns\TextColumn::make('orders_count')->label('Order')->numeric(),
                Tables\Columns\TextColumn::make('completed_orders')->label('Completed')->numeric(),
                Tables\Columns\TextColumn::make('cancelled_orders')->label('Cancelled')->numeric(),
                Tables\Columns\TextColumn::make('gross_revenue')->label('Revenue')->money('IDR'),
                Tables\Columns\TextColumn::make('platform_fee')->label('Platform fee')->money('IDR'),
            ])
            ->paginated([10]);
    }
}
