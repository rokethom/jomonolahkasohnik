<?php
declare(strict_types=1);
namespace App\Filament\Resources;
use App\Filament\Resources\PricingRuleResource\Pages; use App\Models\PricingRing; use Filament\Forms\Form; use Filament\Resources\Resource; use Filament\Tables; use Filament\Tables\Table;
class PricingRuleResource extends Resource
{
    protected static ?string $model = PricingRing::class; protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal'; protected static ?string $navigationGroup = 'Pricing Management'; protected static ?string $navigationLabel = 'Pricing Rules';
    public static function form(Form $form): Form { return $form->schema([]); }
    public static function table(Table $table): Table { return $table->columns([Tables\Columns\TextColumn::make('name'), Tables\Columns\TextColumn::make('min_km'), Tables\Columns\TextColumn::make('max_km')->placeholder('Unlimited'), Tables\Columns\TextColumn::make('formula_type')->badge(), Tables\Columns\TextColumn::make('base_price')->money('IDR'), Tables\Columns\TextColumn::make('service_fee')->money('IDR'), Tables\Columns\TextColumn::make('per_km_price')->money('IDR'), Tables\Columns\TextColumn::make('deduction')->money('IDR')]); }
    public static function canCreate(): bool { return false; } public static function canEdit($record): bool { return false; }
    public static function getPages(): array { return ['index' => Pages\ListPricingRules::route('/')]; }
}
