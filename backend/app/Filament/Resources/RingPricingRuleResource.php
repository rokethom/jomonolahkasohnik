<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\RingPricingRuleResource\Pages;
use App\Filament\Support\PricingCsvTableActions;
use App\Models\Branch;
use App\Models\RingPricingRule;
use App\Models\Service;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RingPricingRuleResource extends Resource
{
    protected static ?string $model = RingPricingRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Master Ring';

    protected static ?string $modelLabel = 'Master Ring';

    protected static ?string $pluralModelLabel = 'Master Ring';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return static::shouldRegisterNavigation()
            && (static::actorCanManageGlobalPricing() || static::scopedBranchId() !== null);
    }

    public static function canEdit($record): bool
    {
        return static::shouldRegisterNavigation() && static::recordInScope($record);
    }

    public static function canDelete($record): bool
    {
        return static::shouldRegisterNavigation() && static::recordInScope($record);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::actorCanManageGlobalPricing()) {
            $query->where('branch_id', static::scopedBranchId() ?? 0);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Section::make('Route Ring')
                    ->description('Gunakan untuk route lapangan yang tidak selalu mengikuti jarak maps, misalnya Pasar Kampung Asembagus ke Pelabuhan Jangkar.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Master')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Asembagus - Jangkar Ring 1'),
                        Forms\Components\Select::make('branch_id')
                            ->label('Branch')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->default(fn (): ?int => self::actorCanManageGlobalPricing() ? null : self::scopedBranchId())
                            ->disabled(fn (): bool => ! self::actorCanManageGlobalPricing())
                            ->dehydrated()
                            ->required(fn (Forms\Get $get): bool => ! self::actorCanManageGlobalPricing() || $get('area_mode') === 'polygon')
                            ->helperText(fn (): string => self::actorCanManageGlobalPricing()
                                ? 'Kosongkan jika berlaku global. Untuk polygon wajib pilih cabang agar tidak bocor antar cabang.'
                                : 'Role cabang hanya boleh mengatur master ring cabangnya sendiri.'),
                        Forms\Components\Select::make('service_type')
                            ->label('Layanan')
                            ->options(fn (): array => self::serviceOptions())
                            ->searchable()
                            ->native(false)
                            ->helperText('Kosongkan jika berlaku untuk semua layanan.'),
                        Forms\Components\Select::make('ring')
                            ->required()
                            ->native(false)
                            ->options([
                                'ring_1' => 'Ring 1',
                                'ring_2' => 'Ring 2',
                                'ring_3' => 'Ring 3',
                            ]),
                        Forms\Components\Select::make('area_mode')
                            ->label('Mode area')
                            ->required()
                            ->native(false)
                            ->default('text')
                            ->live()
                            ->options([
                                'text' => 'Alias teks route',
                                'polygon' => 'Polygon maps',
                            ])
                            ->helperText('Polygon dipakai untuk area Ring 1/2/3 yang digambar di maps. Alias teks tetap tersedia sebagai fallback.'),
                        Forms\Components\TextInput::make('pickup_area')
                            ->label('Asal Area')
                            ->required(fn (Forms\Get $get): bool => $get('area_mode') !== 'polygon')
                            ->maxLength(255)
                            ->placeholder('Pasar Kampung Asembagus'),
                        Forms\Components\TextInput::make('destination_area')
                            ->label('Tujuan Area')
                            ->required(fn (Forms\Get $get): bool => $get('area_mode') !== 'polygon')
                            ->maxLength(255)
                            ->placeholder('Pelabuhan Jangkar'),
                        Forms\Components\TagsInput::make('pickup_aliases')
                            ->label('Alias Asal')
                            ->placeholder('pasar asembagus')
                            ->helperText('Tambahkan variasi nama lokasi yang sering ditulis operator/customer.'),
                        Forms\Components\TagsInput::make('destination_aliases')
                            ->label('Alias Tujuan')
                            ->placeholder('p jangkar'),
                        Forms\Components\Select::make('polygon_match_point')
                            ->label('Titik pengecekan polygon')
                            ->native(false)
                            ->default('destination_then_pickup')
                            ->visible(fn (Forms\Get $get): bool => $get('area_mode') === 'polygon')
                            ->required(fn (Forms\Get $get): bool => $get('area_mode') === 'polygon')
                            ->options([
                                'destination_then_pickup' => 'Tujuan, fallback pickup',
                                'destination' => 'Tujuan saja',
                                'pickup' => 'Pickup saja',
                                'either' => 'Pickup atau tujuan',
                                'both' => 'Pickup dan tujuan',
                            ])
                            ->helperText('Untuk ring harga area, pilihan aman biasanya tujuan lalu fallback pickup.'),
                        Forms\Components\Textarea::make('polygon_coordinates')
                            ->label('Koordinat polygon')
                            ->rows(8)
                            ->formatStateUsing(fn (mixed $state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : (string) ($state ?? ''))
                            ->visible(fn (Forms\Get $get): bool => $get('area_mode') === 'polygon')
                            ->required(fn (Forms\Get $get): bool => $get('area_mode') === 'polygon')
                            ->extraInputAttributes(['id' => 'ring_polygon_coordinates'])
                            ->helperText('Bisa paste GeoJSON dari geojson.io (Feature/FeatureCollection/Polygon), atau format titik: [{"lat":-7.70,"lng":114.00}, ...].'),
                        Forms\Components\ViewField::make('ring_polygon_map')
                            ->label('Gambar polygon ring')
                            ->view('filament.forms.components.ring-polygon-map')
                            ->visible(fn (Forms\Get $get): bool => $get('area_mode') === 'polygon')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Harga & Status')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->label('Harga Jasa')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp'),
                        Forms\Components\Toggle::make('is_bidirectional')
                            ->label('Berlaku dua arah')
                            ->default(true),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                        Forms\Components\Select::make('source')
                            ->required()
                            ->native(false)
                            ->default('manual')
                            ->options([
                                'manual' => 'Manual',
                                'learned' => 'Learned dari koreksi',
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->headerActions(PricingCsvTableActions::make('ring'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Master')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Branch')
                    ->formatStateUsing(fn (RingPricingRule $record): string => $record->branch?->display_name ?? 'Global')
                    ->sortable(),
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->placeholder('Semua'),
                Tables\Columns\TextColumn::make('ring')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str_replace('_', ' ', strtoupper($state)))
                    ->color(fn (string $state): string => match ($state) {
                        'ring_1' => 'success',
                        'ring_2' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('area_mode')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'polygon' ? 'Polygon' : 'Alias')
                    ->color(fn (?string $state): string => $state === 'polygon' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('pickup_area')
                    ->label('Asal')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('destination_area')
                    ->label('Tujuan')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('price')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_bidirectional')
                    ->boolean()
                    ->label('Dua arah'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif'),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'learned' ? 'warning' : 'gray'),
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
                Tables\Filters\SelectFilter::make('ring')
                    ->options([
                        'ring_1' => 'Ring 1',
                        'ring_2' => 'Ring 2',
                        'ring_3' => 'Ring 3',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRingPricingRules::route('/'),
            'create' => Pages\CreateRingPricingRule::route('/create'),
            'edit' => Pages\EditRingPricingRule::route('/{record}/edit'),
        ];
    }

    public static function normalizeScopedData(array $data): array
    {
        if (! static::actorCanManageGlobalPricing()) {
            $data['branch_id'] = static::scopedBranchId();
        }

        $data['area_mode'] = $data['area_mode'] ?? 'text';
        $data['polygon_match_point'] = $data['polygon_match_point'] ?? 'destination_then_pickup';

        if (($data['area_mode'] ?? 'text') === 'polygon') {
            if (blank($data['branch_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'data.branch_id' => 'Master Ring Polygon wajib memilih cabang agar tidak bocor antar cabang.',
                ]);
            }

            $data['pickup_area'] = filled($data['pickup_area'] ?? null) ? $data['pickup_area'] : ($data['name'] ?? 'Polygon pickup');
            $data['destination_area'] = filled($data['destination_area'] ?? null) ? $data['destination_area'] : ($data['name'] ?? 'Polygon destination');
            $data['polygon_coordinates'] = static::normalizePolygonCoordinates($data['polygon_coordinates'] ?? null);

            if (count($data['polygon_coordinates']) < 3) {
                throw ValidationException::withMessages([
                    'data.polygon_coordinates' => 'Polygon Master Ring minimal memiliki 3 titik.',
                ]);
            }
        } else {
            $data['polygon_coordinates'] = null;
        }

        return $data;
    }

    private static function normalizePolygonCoordinates(mixed $value): array
    {
        $points = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($points)) {
            return [];
        }

        $extracted = static::extractPolygonPoints($points);
        $points = $extracted['points'];

        $normalized = collect($points)
            ->map(function (mixed $point): ?array {
                if (! is_array($point)) {
                    return null;
                }

                $lat = $point['lat'] ?? $point['latitude'] ?? null;
                $lng = $point['lng'] ?? $point['longitude'] ?? null;

                if ((! is_numeric($lat) || ! is_numeric($lng)) && isset($point[0], $point[1])) {
                    $lng = $point[0];
                    $lat = $point[1];
                }

                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    return null;
                }

                return [
                    'lat' => round((float) $lat, 8),
                    'lng' => round((float) $lng, 8),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return ($extracted['geometry'] ?? null) === 'line'
            ? static::convexHull($normalized)
            : $normalized;
    }

    private static function extractPolygonPoints(array $value): array
    {
        if (($value['type'] ?? null) === 'FeatureCollection') {
            $linePoints = [];

            foreach ($value['features'] ?? [] as $feature) {
                if (is_array($feature)) {
                    $extracted = static::extractPolygonPoints($feature);

                    if (($extracted['geometry'] ?? null) === 'polygon' && $extracted['points'] !== []) {
                        return $extracted;
                    }

                    if (($extracted['geometry'] ?? null) === 'line') {
                        $linePoints = [...$linePoints, ...$extracted['points']];
                    }
                }
            }

            return ['geometry' => $linePoints !== [] ? 'line' : null, 'points' => $linePoints];
        }

        if (($value['type'] ?? null) === 'Feature') {
            return is_array($value['geometry'] ?? null) ? static::extractPolygonPoints($value['geometry']) : ['geometry' => null, 'points' => []];
        }

        if (($value['type'] ?? null) === 'GeometryCollection') {
            $linePoints = [];

            foreach ($value['geometries'] ?? [] as $geometry) {
                if (is_array($geometry)) {
                    $extracted = static::extractPolygonPoints($geometry);

                    if (($extracted['geometry'] ?? null) === 'polygon' && $extracted['points'] !== []) {
                        return $extracted;
                    }

                    if (($extracted['geometry'] ?? null) === 'line') {
                        $linePoints = [...$linePoints, ...$extracted['points']];
                    }
                }
            }

            return ['geometry' => $linePoints !== [] ? 'line' : null, 'points' => $linePoints];
        }

        if (($value['type'] ?? null) === 'Polygon') {
            return ['geometry' => 'polygon', 'points' => is_array($value['coordinates'][0] ?? null) ? $value['coordinates'][0] : []];
        }

        if (($value['type'] ?? null) === 'MultiPolygon') {
            return ['geometry' => 'polygon', 'points' => is_array($value['coordinates'][0][0] ?? null) ? $value['coordinates'][0][0] : []];
        }

        if (($value['type'] ?? null) === 'LineString') {
            return ['geometry' => 'line', 'points' => is_array($value['coordinates'] ?? null) ? $value['coordinates'] : []];
        }

        if (($value['type'] ?? null) === 'MultiLineString') {
            return ['geometry' => 'line', 'points' => collect($value['coordinates'] ?? [])->filter(fn (mixed $line): bool => is_array($line))->flatten(1)->all()];
        }

        return ['geometry' => 'polygon', 'points' => $value];
    }

    /**
     * @param array<int, array{lat: float, lng: float}> $points
     * @return array<int, array{lat: float, lng: float}>
     */
    private static function convexHull(array $points): array
    {
        $points = collect($points)
            ->unique(fn (array $point): string => $point['lng'].','.$point['lat'])
            ->sortBy([['lng', 'asc'], ['lat', 'asc']])
            ->values()
            ->all();

        if (count($points) <= 3) {
            return $points;
        }

        $cross = fn (array $origin, array $a, array $b): float => (($a['lng'] - $origin['lng']) * ($b['lat'] - $origin['lat']))
            - (($a['lat'] - $origin['lat']) * ($b['lng'] - $origin['lng']));

        $lower = [];
        foreach ($points as $point) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $point) <= 0) {
                array_pop($lower);
            }
            $lower[] = $point;
        }

        $upper = [];
        foreach (array_reverse($points) as $point) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $point) <= 0) {
                array_pop($upper);
            }
            $upper[] = $point;
        }

        array_pop($lower);
        array_pop($upper);

        return array_values([...$lower, ...$upper]);
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->when(! self::actorCanManageGlobalPricing(), fn (Builder $query) => $query->whereKey(self::scopedBranchId() ?? 0))
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }

    private static function serviceOptions(): array
    {
        return Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Service $service): array => [$service->code => $service->name])
            ->all();
    }

    private static function actorCanManageGlobalPricing(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, [UserRole::Admin, UserRole::GM, UserRole::HRD], true);
    }

    private static function scopedBranchId(): ?int
    {
        return Auth::user()?->branch_id;
    }

    private static function recordInScope(?RingPricingRule $record): bool
    {
        if (! $record) {
            return false;
        }

        return static::actorCanManageGlobalPricing()
            || ((int) $record->branch_id === (int) static::scopedBranchId() && $record->branch_id !== null);
    }
}
