<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationPoiResource\Pages;
use App\Filament\Support\RequiresAiDataAccess;
use App\Models\Area;
use App\Models\Branch;
use App\Models\GeojsonRegion;
use App\Models\LivePriceReview;
use App\Models\LocationPoi;
use App\Models\Order;
use App\Services\LocationPoiService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class LocationPoiResource extends Resource
{
    use RequiresAiDataAccess;

    protected static ?string $model = LocationPoi::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'Master Location POI';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nama lokasi')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('category')
                    ->label('Kategori')
                    ->native(false)
                    ->options([
                        'pickup' => 'Pickup',
                        'destination' => 'Tujuan',
                        'store' => 'Toko/Pembelian',
                        'school' => 'Sekolah',
                        'housing' => 'Perumahan',
                        'market' => 'Pasar',
                        'hospital' => 'Rumah sakit',
                        'terminal' => 'Terminal',
                        'other' => 'Lainnya',
                    ]),
                Forms\Components\Select::make('branch_id')
                    ->label('Cabang')
                    ->options(fn (): array => Branch::query()->operationalAreas()->orderBy('branch_code')->get()->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])->all())
                    ->searchable()
                    ->preload()
                    ->native(false),
                Forms\Components\Select::make('area_id')
                    ->label('Area')
                    ->options(fn (): array => Area::query()->with('branch')->orderBy('name')->get()->mapWithKeys(fn (Area $area): array => [$area->id => trim(($area->branch?->display_name ? $area->branch->display_name.' - ' : '').$area->name)])->all())
                    ->searchable()
                    ->preload()
                    ->native(false),
                Forms\Components\TextInput::make('latitude')
                    ->label('Latitude')
                    ->numeric()
                    ->rules(['nullable', 'between:-90,90'])
                    ->helperText('Wajib diisi agar JojoBot bisa memakai POI untuk pricing.'),
                Forms\Components\TextInput::make('longitude')
                    ->label('Longitude')
                    ->numeric()
                    ->rules(['nullable', 'between:-180,180']),
                Forms\Components\TagsInput::make('aliases')
                    ->label('Alias lokal')
                    ->helperText('Contoh: gpm, smpn 1, smp 1, panji mulya.')
                    ->columnSpanFull(),
                Forms\Components\Select::make('source')
                    ->label('Source')
                    ->default('manual')
                    ->native(false)
                    ->options([
                        'manual' => 'Manual',
                        'whatsapp_learning' => 'WhatsApp Learning',
                        'live_price_review' => 'Live Edit Harga',
                        'dashboard_manual' => 'Manual Order',
                        'osm_import' => 'OSM Import',
                        'geojson' => 'GeoJSON',
                    ]),
                Forms\Components\TextInput::make('confidence')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(100)
                    ->default(80),
                Forms\Components\TextInput::make('priority')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->helperText('Aktifkan hanya jika lat/lng sudah benar.')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'area'])->latest('updated_at'))
            ->headerActions([
                Tables\Actions\Action::make('sync_geojson_regions')
                    ->label('Sync dari GeoJSON')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Buat Master POI dari GeoJSON Regions?')
                    ->modalDescription('Semua GeoJSON region aktif yang punya centroid lat/lng akan dibuat atau diperbarui menjadi Master Location POI aktif. Data ini akan dipakai JojoBot sebelum fallback ke GeoJSON/Maps.')
                    ->action(function (): void {
                        $result = self::syncFromGeojsonRegions();

                        Notification::make()
                            ->title('Sync GeoJSON ke POI selesai')
                            ->body("Dibuat: {$result['created']}, diperbarui: {$result['updated']}, dilewati: {$result['skipped']}.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('sync_live_manual_locations')
                    ->label('Sync Live Edit + Manual Order')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Sync lokasi dari Live Edit Harga dan Manual Order?')
                    ->modalDescription('Sistem hanya mengambil alamat yang sudah punya latitude/longitude valid agar Master POI tidak terisi data mentah yang belum jelas.')
                    ->action(function (): void {
                        $result = self::syncFromLiveEditAndManualOrders();

                        Notification::make()
                            ->title('Sync lokasi selesai')
                            ->body("Dibuat: {$result['created']}, diperbarui: {$result['updated']}, dilewati: {$result['skipped']}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Lokasi')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('aliases')->formatStateUsing(fn (mixed $state): string => collect($state ?? [])->take(4)->implode(', '))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('branch.display_name')->label('Cabang')->searchable(),
                Tables\Columns\TextColumn::make('category')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('latitude')->label('Lat')->toggleable(),
                Tables\Columns\TextColumn::make('longitude')->label('Lng')->toggleable(),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('hit_count')->label('Used')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
                Tables\Filters\SelectFilter::make('branch_id')->label('Cabang')->options(fn (): array => Branch::query()->orderBy('name')->get()->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])->all()),
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

    public static function syncFromGeojsonRegions(): array
    {
        $aliases = app(LocationPoiService::class);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        GeojsonRegion::query()
            ->active()
            ->whereNotNull('centroid_lat')
            ->whereNotNull('centroid_lng')
            ->with(['branch', 'area'])
            ->orderBy('name')
            ->chunkById(200, function ($regions) use ($aliases, &$created, &$updated, &$skipped): void {
                foreach ($regions as $region) {
                    if (! is_numeric($region->centroid_lat) || ! is_numeric($region->centroid_lng)) {
                        $skipped++;

                        continue;
                    }

                    $poi = LocationPoi::query()->updateOrCreate(
                        ['geojson_region_id' => $region->id],
                        [
                            'branch_id' => $region->branch_id,
                            'area_id' => $region->area_id,
                            'name' => $region->name,
                            'aliases' => $aliases->aliasesFor($region->name),
                            'category' => 'destination',
                            'latitude' => $region->centroid_lat,
                            'longitude' => $region->centroid_lng,
                            'source' => 'geojson',
                            'confidence' => 85,
                            'priority' => 10,
                            'is_active' => true,
                        ],
                    );

                    $poi->wasRecentlyCreated ? $created++ : $updated++;
                }
            });

        Cache::flush();

        return compact('created', 'updated', 'skipped');
    }

    public static function syncFromLiveEditAndManualOrders(): array
    {
        $poiService = app(LocationPoiService::class);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        LivePriceReview::query()
            ->whereNotNull('order_payload')
            ->chunkById(100, function ($reviews) use ($poiService, &$created, &$updated, &$skipped): void {
                foreach ($reviews as $review) {
                    foreach (self::poiCandidatesFromPayload($review->order_payload ?? [], [
                        'branch_id' => $review->branch_id,
                        'area_id' => null,
                        'source' => 'live_price_review',
                        'confidence' => 78,
                        'priority' => 18,
                    ]) as $candidate) {
                        self::syncPoiCandidate($candidate, $poiService, $created, $updated, $skipped);
                    }
                }
            });

        Order::query()
            ->where('source', 'dashboard_manual')
            ->with('points')
            ->chunkById(100, function ($orders) use ($poiService, &$created, &$updated, &$skipped): void {
                foreach ($orders as $order) {
                    foreach (self::poiCandidatesFromOrder($order) as $candidate) {
                        self::syncPoiCandidate($candidate, $poiService, $created, $updated, $skipped);
                    }
                }
            });

        Cache::flush();

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function poiCandidatesFromOrder(Order $order): array
    {
        $candidates = self::poiCandidatesFromPayload([
            'pickup_address' => $order->pickup_address,
            'pickup_lat' => $order->pickup_lat,
            'pickup_lng' => $order->pickup_lng,
            'destination_address' => $order->destination_address,
            'destination_lat' => $order->destination_lat,
            'destination_lng' => $order->destination_lng,
        ], [
            'branch_id' => $order->branch_id,
            'area_id' => $order->area_id,
            'source' => 'dashboard_manual',
            'confidence' => 82,
            'priority' => 22,
        ]);

        foreach ($order->points as $point) {
            $candidates[] = [
                'name' => $point->address ?: $point->label,
                'category' => $point->sequence === 0 ? 'pickup' : 'destination',
                'latitude' => $point->latitude ?? $point->lat ?? null,
                'longitude' => $point->longitude ?? $point->lng ?? null,
                'branch_id' => $order->branch_id,
                'area_id' => $order->area_id,
                'source' => 'dashboard_manual',
                'confidence' => 80,
                'priority' => 20,
            ];
        }

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function poiCandidatesFromPayload(array $payload, array $defaults): array
    {
        $candidates = [
            [
                'name' => $payload['pickup_address'] ?? data_get($payload, 'service_payload.pickup_address'),
                'category' => 'pickup',
                'latitude' => $payload['pickup_lat'] ?? $payload['pickup_latitude'] ?? data_get($payload, 'pickup.lat'),
                'longitude' => $payload['pickup_lng'] ?? $payload['pickup_longitude'] ?? data_get($payload, 'pickup.lng'),
            ],
            [
                'name' => $payload['destination_address'] ?? $payload['destination_text'] ?? data_get($payload, 'service_payload.destination_address'),
                'category' => 'destination',
                'latitude' => $payload['destination_lat'] ?? $payload['destination_latitude'] ?? data_get($payload, 'destination.lat'),
                'longitude' => $payload['destination_lng'] ?? $payload['destination_longitude'] ?? data_get($payload, 'destination.lng'),
            ],
        ];

        foreach ((array) ($payload['points'] ?? []) as $index => $point) {
            if (! is_array($point)) {
                continue;
            }

            $candidates[] = [
                'name' => $point['address'] ?? $point['name'] ?? $point['label'] ?? null,
                'category' => $index === 0 ? 'pickup' : 'destination',
                'latitude' => $point['lat'] ?? $point['latitude'] ?? null,
                'longitude' => $point['lng'] ?? $point['longitude'] ?? null,
            ];
        }

        return collect($candidates)
            ->map(fn (array $candidate): array => [
                ...$candidate,
                ...$defaults,
            ])
            ->values()
            ->all();
    }

    private static function syncPoiCandidate(array $candidate, LocationPoiService $poiService, int &$created, int &$updated, int &$skipped): void
    {
        $name = trim((string) ($candidate['name'] ?? ''));
        $lat = $candidate['latitude'] ?? null;
        $lng = $candidate['longitude'] ?? null;

        if ($name === '' || ! is_numeric($lat) || ! is_numeric($lng) || self::isIgnoredPoiName($name)) {
            $skipped++;

            return;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            $skipped++;

            return;
        }

        $branchId = $candidate['branch_id'] ?? null;
        $category = (string) ($candidate['category'] ?? 'destination');

        $poi = LocationPoi::query()
            ->where('branch_id', $branchId)
            ->where('category', $category)
            ->where('name', $name)
            ->first();

        $wasNew = ! $poi;
        $poi ??= new LocationPoi([
            'branch_id' => $branchId,
            'category' => $category,
            'name' => $name,
        ]);

        $poi->fill([
            'area_id' => $candidate['area_id'] ?? $poi->area_id,
            'aliases' => collect([...(array) ($poi->aliases ?? []), ...$poiService->aliasesFor($name)])
                ->filter()
                ->unique(fn (mixed $alias): string => $poiService->normalize((string) $alias))
                ->take(40)
                ->values()
                ->all(),
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => $candidate['source'] ?? 'dashboard_manual',
            'confidence' => max((int) ($poi->confidence ?: 0), (int) ($candidate['confidence'] ?? 75)),
            'priority' => max((int) ($poi->priority ?: 0), (int) ($candidate['priority'] ?? 10)),
            'is_active' => true,
        ])->save();

        $wasNew ? $created++ : $updated++;
    }

    private static function isIgnoredPoiName(string $name): bool
    {
        $normalized = app(LocationPoiService::class)->normalize($name);

        return in_array($normalized, [
            'rumah',
            'rumah saya',
            'alamat saya',
            'alamat customer',
            'lokasi saya',
            'posisi saya',
            'titik saya',
            'saya',
        ], true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocationPois::route('/'),
            'create' => Pages\CreateLocationPoi::route('/create'),
            'edit' => Pages\EditLocationPoi::route('/{record}/edit'),
        ];
    }
}
