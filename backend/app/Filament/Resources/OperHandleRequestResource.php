<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Models\OperHandleRequest;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OperHandleRequestResource extends Resource
{
    protected static ?string $model = OperHandleRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Oper Handle';

    public static function canDelete($record): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order.order_code')->label('Order')->searchable(),
                Tables\Columns\TextColumn::make('driver.user.username')->label('Driver')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('reason')->limit(50),
                Tables\Columns\TextColumn::make('operator_approved_at')->dateTime(),
                Tables\Columns\TextColumn::make('spv_approved_at')->dateTime(),
                Tables\Columns\TextColumn::make('decider.name')->label('Diputuskan oleh'),
                Tables\Columns\TextColumn::make('decided_at')->label('Waktu keputusan')->dateTime(),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => static::canDeleteAny()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => static::canDeleteAny()),
                ])->visible(fn (): bool => static::canDeleteAny()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => OperHandleRequestResource\Pages\ListOperHandleRequests::route('/'),
        ];
    }
}
