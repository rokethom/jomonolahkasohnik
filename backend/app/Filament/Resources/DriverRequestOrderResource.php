<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverRequestOrderResource\Pages;
use App\Models\Order;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DriverRequestOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Driver Request Orders';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('driver.user.name')->label('Driver')->searchable(),
                Tables\Columns\TextColumn::make('service_code')->badge(),
                Tables\Columns\TextColumn::make('pickup_address')->limit(30)->searchable(),
                Tables\Columns\TextColumn::make('destination_address')->limit(30)->searchable(),
                Tables\Columns\TextColumn::make('price')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('raw_text')->limit(50)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('source', 'driver_request')
            ->with(['driver.user', 'service']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverRequestOrders::route('/'),
        ];
    }
}
