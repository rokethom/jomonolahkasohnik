<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GeofenceAreaResource\Pages;
use App\Models\Branch;
use App\Models\GeofenceArea;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GeofenceAreaResource extends Resource
{
    protected static ?string $model = GeofenceArea::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Location';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Geofence Area')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('branch_id')
                            ->label('Branch')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('center_latitude')
                            ->numeric()
                            ->required()
                            ->live(onBlur: true)
                            ->extraInputAttributes(['id' => 'geofence_center_latitude']),
                        Forms\Components\TextInput::make('center_longitude')
                            ->numeric()
                            ->required()
                            ->live(onBlur: true)
                            ->extraInputAttributes(['id' => 'geofence_center_longitude']),
                        Forms\Components\TextInput::make('radius_meters')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('meters')
                            ->required()
                            ->live(onBlur: true)
                            ->extraInputAttributes(['id' => 'geofence_radius_meters']),
                        Forms\Components\TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        Forms\Components\View::make('filament.forms.components.geofence-map-preview')
                            ->columnSpanFull(),
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
                    ->formatStateUsing(fn (GeofenceArea $record): string => $record->branch?->display_name ?? '-')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('center_latitude')
                    ->sortable(),
                Tables\Columns\TextColumn::make('center_longitude')
                    ->sortable(),
                Tables\Columns\TextColumn::make('radius_meters')
                    ->suffix(' m')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('priority')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn (): array => self::branchOptions()),
                Tables\Filters\TernaryFilter::make('is_active'),
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
            ->defaultSort('priority', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeofenceAreas::route('/'),
            'create' => Pages\CreateGeofenceArea::route('/create'),
            'edit' => Pages\EditGeofenceArea::route('/{record}/edit'),
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
