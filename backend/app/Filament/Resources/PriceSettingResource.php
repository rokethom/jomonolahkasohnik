<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceSettingResource\Pages;
use App\Models\Branch;
use App\Models\PriceSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class PriceSettingResource extends Resource
{
    protected static ?string $model = PriceSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Distance Price Settings';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function normalizePricingData(array $data): array
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['is_formula'] = (bool) ($data['is_formula'] ?? false);
        $data['branch_id'] = filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;
        $data['max_km'] = filled($data['max_km'] ?? null) ? $data['max_km'] : null;

        if ($data['is_formula']) {
            $data['price'] = null;
            $data['per_km_rate'] = (int) ($data['per_km_rate'] ?? 0);
            $data['subtract_value'] = (int) ($data['subtract_value'] ?? 0);
        } else {
            $data['price'] = (int) ($data['price'] ?? 0);
            $data['per_km_rate'] = null;
            $data['subtract_value'] = 0;
        }

        return $data;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pricing Rule')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->helperText('Matikan rule tanpa menghapus histori setting.'),
                        Forms\Components\Select::make('branch_id')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->helperText('Kosongkan untuk tarif global.'),
                        Forms\Components\TextInput::make('min_km')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->suffix('KM'),
                        Forms\Components\TextInput::make('max_km')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('KM')
                            ->helperText('Kosongkan untuk tanpa batas atas.'),
                        Forms\Components\Toggle::make('is_formula')
                            ->label('Use formula')
                            ->live(),
                        Forms\Components\TextInput::make('price')
                            ->numeric()
                            ->prefix('Rp')
                            ->visible(fn (Forms\Get $get): bool => ! (bool) $get('is_formula'))
                            ->required(fn (Forms\Get $get): bool => ! (bool) $get('is_formula')),
                        Forms\Components\TextInput::make('per_km_rate')
                            ->numeric()
                            ->prefix('Rp')
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('is_formula'))
                            ->required(fn (Forms\Get $get): bool => (bool) $get('is_formula')),
                        Forms\Components\TextInput::make('subtract_value')
                            ->numeric()
                            ->prefix('Rp')
                            ->default(0)
                            ->visible(fn (Forms\Get $get): bool => (bool) $get('is_formula')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Branch')
                    ->formatStateUsing(fn ($record): string => $record->branch?->display_name ?? 'Global')
                    ->placeholder('Global')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active')
                    ->sortable(),
                Tables\Columns\TextColumn::make('min_km')
                    ->suffix(' KM')
                    ->sortable(),
                Tables\Columns\TextColumn::make('max_km')
                    ->placeholder('Unlimited')
                    ->suffix(' KM')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_formula')
                    ->boolean()
                    ->label('Formula'),
                Tables\Columns\TextColumn::make('price')
                    ->money('IDR')
                    ->placeholder('-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('per_km_rate')
                    ->money('IDR')
                    ->placeholder('-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subtract_value')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn (): array => self::branchOptions())
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_formula')
                    ->label('Formula'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('min_km');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceSettings::route('/'),
            'create' => Pages\CreatePriceSetting::route('/create'),
            'edit' => Pages\EditPriceSetting::route('/{record}/edit'),
        ];
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }
}
