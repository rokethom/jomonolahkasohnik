<?php

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class GeojsonRegionResource extends Resource
{
    protected static ?string $model = GeojsonRegion::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Master Data GeoJSON';

    protected static ?string $navigationLabel = 'GeoJSON Regions';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true || Auth::user()?->hasPermission('manage_cms') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama region')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('branch_id')
                ->label('Cabang')
                ->options(fn (): array => self::branchOptions())
                ->searchable()
                ->preload()
                ->native(false),
            Forms\Components\Select::make('area_id')
                ->label('Area layanan')
                ->options(fn (): array => self::areaOptions())
                ->searchable()
                ->preload()
                ->native(false),
            Forms\Components\TextInput::make('version')
                ->numeric()
                ->default(1)
                ->required(),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            Forms\Components\Textarea::make('geojson')
                ->label('GeoJSON Polygon / MultiPolygon')
                ->required()
                ->rows(14)
                ->columnSpanFull()
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? ''))
                ->helperText('Paste GeoJSON dari geojson.io di sini. Jika berisi FeatureCollection, sistem otomatis membuat baris tabel per feature. Harga tetap dikontrol dari Master Ring.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'area']))
            ->headerActions([
                Tables\Actions\Action::make('delete_all')
                    ->label('Hapus semua')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Hapus semua GeoJSON region?')
                    ->modalDescription('Semua data GeoJSON region akan dihapus dari master data. Master Ring tidak ikut terhapus.')
                    ->modalSubmitActionLabel('Ya, hapus semua')
                    ->action(function (): void {
                        GeojsonRegion::query()->delete();
                        Cache::flush();
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Region')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Cabang')
                    ->formatStateUsing(fn (GeojsonRegion $record): string => $record->branch?->display_name ?? '-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('area_id')
                    ->label('Area')
                    ->formatStateUsing(fn (GeojsonRegion $record): string => $record->area?->name ?? '-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('geometry_type')
                    ->badge(),
                Tables\Columns\TextColumn::make('centroid_lat')
                    ->label('Lat')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('centroid_lng')
                    ->label('Lng')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('distance_from_branch')
                    ->label('Jarak')
                    ->state(fn (GeojsonRegion $record): ?float => self::distanceFromBranch($record))
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '-' : number_format($state, 2, ',', '.').' km')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(fn (): bool => Cache::flush()),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->after(fn (): bool => Cache::flush()),
            ]);
    }

    public static function normalizeGeojsonData(array $data): array
    {
        $parsed = app(GeojsonParserService::class)->parse($data['geojson']);
        Cache::flush();

        return [
            ...$data,
            ...$parsed,
        ];
    }

    public static function normalizeGeojsonRows(array $data): array
    {
        $rows = app(GeojsonParserService::class)->parseRows($data['geojson']);
        Cache::flush();

        return collect($rows)
            ->map(function (array $row) use ($data): array {
                $name = trim((string) ($row['name'] ?? ''));

                unset($row['name']);

                return [
                    ...$data,
                    ...$row,
                    'name' => $name !== '' ? $name : $data['name'],
                ];
            })
            ->values()
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGeojsonRegions::route('/'),
            'create' => Pages\CreateGeojsonRegion::route('/create'),
            'edit' => Pages\EditGeojsonRegion::route('/{record}/edit'),
        ];
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('branch_code')
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }

    private static function areaOptions(): array
    {
        return Area::query()
            ->with('branch')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Area $area): array => [
                $area->id => trim(($area->branch?->display_name ? $area->branch->display_name.' - ' : '').$area->name.' ('.$area->code.')'),
            ])
            ->all();
    }

    private static function distanceFromBranch(GeojsonRegion $record): ?float
    {
        $branch = $record->branch;
        if (! $branch || ! is_numeric($branch->latitude) || ! is_numeric($branch->longitude)) {
            return null;
        }

        if (! is_numeric($record->centroid_lat) || ! is_numeric($record->centroid_lng)) {
            return null;
        }

        $earthRadiusKm = 6371.0;
        $lat1 = (float) $branch->latitude;
        $lng1 = (float) $branch->longitude;
        $lat2 = (float) $record->centroid_lat;
        $lng2 = (float) $record->centroid_lng;

        $latDistance = deg2rad($lat2 - $lat1);
        $lngDistance = deg2rad($lng2 - $lng1);
        $a = sin($latDistance / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDistance / 2) ** 2;

        return round($earthRadiusKm * (2 * atan2(sqrt($a), sqrt(1 - $a))), 2);
    }
}
