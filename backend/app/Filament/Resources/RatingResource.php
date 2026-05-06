<?php

namespace App\Filament\Resources;

use App\Models\Rating;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RatingResource extends Resource
{
    protected static ?string $model = Rating::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Analytics';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.order_code')->label('Order')->searchable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('driver.user.name')->label('Driver')->searchable(),
                Tables\Columns\TextColumn::make('rating')->badge()->sortable(),
                Tables\Columns\TextColumn::make('comment')->limit(50),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => RatingResource\Pages\ListRatings::route('/'),
        ];
    }
}
