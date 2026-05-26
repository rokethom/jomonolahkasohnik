<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\DriverRequestOrderResource\Pages;
use App\Models\Order;
use Filament\Infolists;
use Filament\Infolists\Infolist;
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
                Tables\Columns\TextColumn::make('order_code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('driver.user.username')->label('Driver')->searchable(),
                Tables\Columns\TextColumn::make('service_code')->badge(),
                Tables\Columns\TextColumn::make('pickup_address')->limit(30)->searchable(),
                Tables\Columns\TextColumn::make('destination_address')->limit(30)->searchable(),
                Tables\Columns\TextColumn::make('price')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('raw_text')->limit(50)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => static::canDeleteAny()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => static::canDeleteAny()),
                ])->visible(fn (): bool => static::canDeleteAny()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Detail Request')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('order_code')
                            ->label('Kode request')
                            ->copyable()
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->formatStateUsing(fn ($state): string => $state instanceof \BackedEnum ? (string) $state->value : (filled($state) ? (string) $state : '-'))
                            ->badge(),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('driver.user.username')
                            ->label('Driver')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('driver.user.phone')
                            ->label('No. driver')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('service_code')
                            ->label('Layanan')
                            ->badge(),
                        Infolists\Components\TextEntry::make('branch.name')
                            ->label('Cabang / Area')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('source')
                            ->label('Sumber')
                            ->badge(),
                        Infolists\Components\TextEntry::make('geocoded_by')
                            ->label('Geocode')
                            ->placeholder('-')
                            ->badge(),
                    ]),
                Infolists\Components\Section::make('Rute')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('pickup_address')
                            ->label('Pickup')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('destination_address')
                            ->label('Tujuan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('pickup_lat')
                            ->label('Pickup lat')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('pickup_lng')
                            ->label('Pickup lng')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('destination_lat')
                            ->label('Tujuan lat')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('destination_lng')
                            ->label('Tujuan lng')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('distance_km')
                            ->label('Jarak')
                            ->formatStateUsing(fn ($state): string => filled($state) ? number_format((float) $state, 2, ',', '.').' km' : '-'),
                    ]),
                Infolists\Components\Section::make('Harga')
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('price')
                            ->label('Tarif')
                            ->formatStateUsing(fn ($state): string => self::formatRupiah($state)),
                        Infolists\Components\TextEntry::make('service_charge')
                            ->label('Service fee')
                            ->formatStateUsing(fn ($state): string => self::formatRupiah($state)),
                        Infolists\Components\TextEntry::make('extra_charge')
                            ->label('Tambahan')
                            ->formatStateUsing(fn ($state): string => self::formatRupiah($state)),
                        Infolists\Components\TextEntry::make('total_price')
                            ->label('Total')
                            ->formatStateUsing(fn ($state): string => self::formatRupiah($state)),
                        Infolists\Components\TextEntry::make('pricing_breakdown.routing_provider')
                            ->label('Provider jarak')
                            ->placeholder('-'),
                        Infolists\Components\TextEntry::make('pricing_breakdown.ring')
                            ->label('Ring')
                            ->placeholder('-'),
                    ]),
                Infolists\Components\Section::make('Teks Request')
                    ->schema([
                        Infolists\Components\TextEntry::make('raw_text')
                            ->label('Raw text')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('source', 'driver_request')
            ->with(['branch', 'driver.user', 'service']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverRequestOrders::route('/'),
            'view' => Pages\ViewDriverRequestOrder::route('/{record}'),
        ];
    }

    private static function formatRupiah(mixed $state): string
    {
        if (! filled($state)) {
            return '-';
        }

        return 'Rp '.number_format((float) $state, 0, ',', '.');
    }
}
