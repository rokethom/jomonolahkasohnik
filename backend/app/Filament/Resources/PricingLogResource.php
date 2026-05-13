<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\PricingLogResource\Pages; use App\Models\PricingLog; use Filament\Forms\Form; use Filament\Resources\Resource; use Filament\Tables; use Filament\Tables\Table;
class PricingLogResource extends Resource
{
    protected static ?string $model = PricingLog::class; protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square'; protected static ?string $navigationGroup = 'Pricing Management'; protected static ?string $navigationLabel = 'Pricing Logs';
    public static function form(Form $form): Form { return $form->schema([]); }
    public static function table(Table $table): Table { return $table->defaultSort('created_at', 'desc')->columns([Tables\Columns\TextColumn::make('branch.name')->label('Branch'), Tables\Columns\TextColumn::make('area.name')->label('Area'), Tables\Columns\TextColumn::make('pricingRing.name')->label('Ring'), Tables\Columns\TextColumn::make('distance_km')->label('KM')->sortable(), Tables\Columns\TextColumn::make('calculated_price')->money('IDR')->sortable(), Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()]); }
    public static function canCreate(): bool { return false; } public static function canEdit($record): bool { return false; }
    public static function getPages(): array { return ['index' => Pages\ListPricingLogs::route('/')]; }
}
