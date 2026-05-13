<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GeojsonRegionResource\Pages;
use App\Models\Area;
use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Services\Geojson\GeojsonParserService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class GeojsonRegionResource extends Resource
{
    protected static ?string $model = GeojsonRegion::class;
    protected static ?string $navigationIcon = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Spatial Management';
    protected static ?string $navigationLabel = 'GeoJSON Regions';

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true || Auth::user()?->hasPermission('manage_cms') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\Select::make('branch_id')->label('Branch')->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))->searchable()->preload(),
            Forms\Components\Select::make('area_id')->label('Area')->options(fn () => Area::query()->orderBy('name')->pluck('name', 'id'))->searchable()->preload(),
            Forms\Components\TextInput::make('version')->numeric()->default(1)->required(),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\Textarea::make('geojson')
                ->label('GeoJSON Polygon / MultiPolygon')
                ->required()
                ->rows(14)
                ->columnSpanFull()
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? ''))
                ->helperText('GeoJSON hanya untuk spatial intelligence, geofence, coverage, dan area detection. Tidak dipakai sebagai pricing ring.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable(),
            Tables\Columns\TextColumn::make('area.name')->label('Area')->sortable(),
            Tables\Columns\TextColumn::make('geometry_type')->badge(),
            Tables\Columns\TextColumn::make('version')->sortable(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
            Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function normalizeGeojsonData(array $data): array
    {
        $parsed = app(GeojsonParserService::class)->parse($data['geojson']);

        return [
            ...$data,
            ...$parsed,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeojsonRegions::route('/'),
            'create' => Pages\CreateGeojsonRegion::route('/create'),
            'edit' => Pages\EditGeojsonRegion::route('/{record}/edit'),
        ];
    }
}
