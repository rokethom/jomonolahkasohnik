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
                    ->description('Master Ring adalah kontrol harga berbasis jarak. GeoJSON dikelola dari menu Master Data GeoJSON, bukan dari form ini.')
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
                            ->required(fn (): bool => ! self::actorCanManageGlobalPricing())
                            ->helperText(fn (): string => self::actorCanManageGlobalPricing()
                                ? 'Kosongkan jika berlaku global. AI Pricing tetap membaca cabang/area dari GeoJSON bila koordinat tersedia.'
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
                            ->live()
                            ->default('ring_1')
                            ->options([
                                'ring_1' => 'Ring 1',
                                'ring_2' => 'Ring 2',
                                'ring_3' => 'Ring 3',
                            ])
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                                $set('min_km', self::defaultRingMinKm((string) $state));
                                $set('max_km', self::defaultRingMaxKm((string) $state));
                                $set('service_fee', self::defaultRingServiceFee((string) $state));
                                $set('priority', self::defaultRingPriority((string) $state));

                                if ($state === 'ring_3') {
                                    $set('pricing_mode', 'formula');
                                    $set('price', 0);
                                    $set('per_km_rate', 1900);
                                    $set('subtract_value', 7000);
                                } else {
                                    $set('pricing_mode', 'flat');
                                    $set('price', $state === 'ring_2' ? 12000 : 6000);
                                    $set('per_km_rate', null);
                                    $set('subtract_value', 0);
                                }
                            }),
                        Forms\Components\TextInput::make('min_km')
                            ->label('Min KM dari pusat cabang')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Forms\Components\TextInput::make('max_km')
                            ->label('Max KM dari pusat cabang')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Kosongkan untuk unlimited.'),
                        Forms\Components\TextInput::make('priority')
                            ->label('Priority')
                            ->numeric()
                            ->default(300)
                            ->required()
                            ->helperText('Priority tertinggi dipilih jika ada lebih dari satu kontrol jarak yang cocok. Ring 1 default 300, Ring 2 200, Ring 3 100.'),
                        Forms\Components\TextInput::make('pickup_area')
                            ->label('Asal Area')
                            ->maxLength(255)
                            ->helperText('Opsional. Dipakai sebagai alias/rute khusus, bukan polygon.')
                            ->placeholder('Pasar Kampung Asembagus'),
                        Forms\Components\TextInput::make('destination_area')
                            ->label('Tujuan Area')
                            ->maxLength(255)
                            ->helperText('Opsional. Dipakai sebagai alias/rute khusus, bukan polygon.')
                            ->placeholder('Pelabuhan Jangkar'),
                        Forms\Components\TagsInput::make('pickup_aliases')
                            ->label('Alias Asal')
                            ->placeholder('pasar asembagus')
                            ->helperText('Tambahkan variasi nama lokasi yang sering ditulis operator/customer.'),
                        Forms\Components\TagsInput::make('destination_aliases')
                            ->label('Alias Tujuan')
                            ->placeholder('p jangkar'),
                        Forms\Components\Select::make('match_type')
                            ->label('Cross ring')
                            ->native(false)
                            ->live()
                            ->default('point')
                            ->options([
                                'point' => 'Single ring',
                                'cross' => 'Cross ring',
                            ]),
                        Forms\Components\Select::make('pickup_ring')
                            ->label('Pickup ring')
                            ->native(false)
                            ->options([
                                'ring_1' => 'Ring 1',
                                'ring_2' => 'Ring 2',
                                'ring_3' => 'Ring 3',
                            ])
                            ->placeholder('Auto')
                            ->visible(fn (Forms\Get $get): bool => $get('match_type') === 'cross'),
                        Forms\Components\Select::make('destination_ring')
                            ->label('Destination ring')
                            ->native(false)
                            ->options([
                                'ring_1' => 'Ring 1',
                                'ring_2' => 'Ring 2',
                                'ring_3' => 'Ring 3',
                            ])
                            ->placeholder('Auto')
                            ->visible(fn (Forms\Get $get): bool => $get('match_type') === 'cross'),
                    ]),
                Forms\Components\Section::make('Harga & Status')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('pricing_mode')
                            ->label('Mode harga')
                            ->native(false)
                            ->live()
                            ->default('flat')
                            ->required()
                            ->options([
                                'flat' => 'Flat / harga tetap',
                                'formula' => 'Formula KM',
                            ]),
                        Forms\Components\TextInput::make('price')
                            ->label(fn (Forms\Get $get): string => $get('pricing_mode') === 'formula' ? 'Harga minimum' : 'Harga Jasa')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(6000)
                            ->prefix('Rp'),
                        Forms\Components\TextInput::make('per_km_rate')
                            ->label('Rate / KM')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->visible(fn (Forms\Get $get): bool => $get('pricing_mode') === 'formula'),
                        Forms\Components\TextInput::make('subtract_value')
                            ->label('Subtract')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->visible(fn (Forms\Get $get): bool => $get('pricing_mode') === 'formula'),
                        Forms\Components\TextInput::make('service_fee')
                            ->label('Service fee')
                            ->numeric()
                            ->minValue(0)
                            ->default(1000)
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
                    ->formatStateUsing(fn (): string => 'Kontrol harga')
                    ->color('gray'),
                Tables\Columns\TextColumn::make('pickup_area')
                    ->label('Asal')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('destination_area')
                    ->label('Tujuan')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Harga')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('min_km')
                    ->label('Min KM')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('max_km')
                    ->label('Max KM')
                    ->placeholder('Unlimited')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('service_fee')
                    ->label('Service fee')
                    ->money('IDR')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Priority')
                    ->sortable()
                    ->toggleable(),
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
        $data['polygon_match_point'] = null;
        $data['polygon_coordinates'] = null;
        $data['match_type'] = $data['match_type'] ?? 'point';
        $data['pickup_ring'] = filled($data['pickup_ring'] ?? null) ? $data['pickup_ring'] : null;
        $data['destination_ring'] = filled($data['destination_ring'] ?? null) ? $data['destination_ring'] : null;
        $data['min_km'] = (float) ($data['min_km'] ?? static::defaultRingMinKm((string) ($data['ring'] ?? 'ring_1')));
        $data['max_km'] = array_key_exists('max_km', $data) && $data['max_km'] !== null && $data['max_km'] !== ''
            ? (float) $data['max_km']
            : static::defaultRingMaxKm((string) ($data['ring'] ?? 'ring_1'));
        $data['pricing_mode'] = $data['pricing_mode'] ?? (((int) ($data['per_km_rate'] ?? 0) > 0) ? 'formula' : 'flat');
        if ($data['pricing_mode'] === 'formula') {
            $data['per_km_rate'] = (int) ($data['per_km_rate'] ?? 0);
            $data['subtract_value'] = (int) ($data['subtract_value'] ?? 0);
            $data['price'] = (int) ($data['price'] ?? 0);
        } else {
            $data['per_km_rate'] = null;
            $data['subtract_value'] = 0;
            $data['price'] = (int) ($data['price'] ?? 0);
        }
        $data['service_fee'] = (int) ($data['service_fee'] ?? static::defaultRingServiceFee((string) ($data['ring'] ?? 'ring_1')));
        $data['priority'] = (int) ($data['priority'] ?? static::defaultRingPriority((string) ($data['ring'] ?? 'ring_1')));

        $data['pickup_area'] = filled($data['pickup_area'] ?? null) ? $data['pickup_area'] : '*';
        $data['destination_area'] = filled($data['destination_area'] ?? null) ? $data['destination_area'] : '*';

        return $data;
    }

    private static function defaultRingMinKm(string $ring): float
    {
        return match ($ring) {
            'ring_2' => 4.1,
            'ring_3' => 9.1,
            default => 0.0,
        };
    }

    private static function defaultRingMaxKm(string $ring): ?float
    {
        return match ($ring) {
            'ring_1' => 4.0,
            'ring_2' => 9.0,
            default => null,
        };
    }

    private static function defaultRingServiceFee(string $ring): int
    {
        return in_array($ring, ['ring_1', 'ring_2'], true) ? 1000 : 0;
    }

    private static function defaultRingPriority(string $ring): int
    {
        return match ($ring) {
            'ring_1' => 300,
            'ring_2' => 200,
            'ring_3' => 100,
            default => 0,
        };
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
