<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PricingRingResource\Pages;
use App\Models\PricingRing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class PricingRingResource extends Resource
{
    protected static ?string $model = PricingRing::class;
    protected static ?string $navigationIcon = 'heroicon-o-calculator';
    protected static ?string $navigationGroup = 'Pricing Management';
    protected static ?string $navigationLabel = 'Pricing Rings';

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\TextInput::make('min_km')->numeric()->required(),
            Forms\Components\TextInput::make('max_km')->numeric()->helperText('Kosongkan untuk unlimited.'),
            Forms\Components\Select::make('formula_type')->required()->native(false)->options(['FIXED' => 'FIXED', 'DISTANCE' => 'DISTANCE']),
            Forms\Components\TextInput::make('base_price')->numeric()->required()->prefix('Rp'),
            Forms\Components\TextInput::make('service_fee')->numeric()->required()->prefix('Rp'),
            Forms\Components\TextInput::make('per_km_price')->numeric()->prefix('Rp'),
            Forms\Components\TextInput::make('deduction')->numeric()->required()->prefix('Rp'),
            Forms\Components\TextInput::make('priority')->numeric()->required(),
            Forms\Components\DatePicker::make('effective_date'),
            Forms\Components\DatePicker::make('expired_date'),
            Forms\Components\TextInput::make('version')->numeric()->required()->default(1),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('priority', 'desc')->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('min_km')->label('Min KM'),
            Tables\Columns\TextColumn::make('max_km')->label('Max KM')->placeholder('Unlimited'),
            Tables\Columns\TextColumn::make('formula_type')->badge(),
            Tables\Columns\TextColumn::make('base_price')->money('IDR'),
            Tables\Columns\TextColumn::make('service_fee')->money('IDR'),
            Tables\Columns\TextColumn::make('priority')->sortable(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function clearCache(): void
    {
        Cache::forget('jojobot:pricing_rings:active');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPricingRings::route('/'), 'create' => Pages\CreatePricingRing::route('/create'), 'edit' => Pages\EditPricingRing::route('/{record}/edit')];
    }
}
