<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationPoiResource\Pages;
use App\Models\Area;
use App\Models\Branch;
use App\Models\LocationPoi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LocationPoiResource extends Resource
{
    protected static ?string $model = LocationPoi::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'JojoBot';

    protected static ?string $navigationLabel = 'Master Location POI';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('edit_tarif') === true || auth()->user()?->hasPermission('manage_cms') === true;
    }

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
                    ->options(fn (): array => Branch::query()->orderBy('branch_code')->get()->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])->all())
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocationPois::route('/'),
            'create' => Pages\CreateLocationPoi::route('/create'),
            'edit' => Pages\EditLocationPoi::route('/{record}/edit'),
        ];
    }
}
