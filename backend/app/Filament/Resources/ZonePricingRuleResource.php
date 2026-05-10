<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ZonePricingRuleResource\Pages;
use App\Models\Branch;
use App\Models\GeofenceArea;
use App\Models\Service;
use App\Models\ZonePricingRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ZonePricingRuleResource extends Resource
{
    protected static ?string $model = ZonePricingRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Pricing';

    protected static ?string $navigationLabel = 'Zone Pricing';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Zone Pricing Rule')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('branch_id')
                            ->label('Cabang')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->helperText('Kosongkan untuk rule global. Jika diisi, daftar geofence akan mengikuti cabang.'),
                        Forms\Components\Select::make('geofence_area_id')
                            ->label('Zona / Geofence')
                            ->options(fn (Forms\Get $get): array => self::geofenceOptions($get('branch_id') ? (int) $get('branch_id') : null))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),
                        Forms\Components\Select::make('service_type')
                            ->label('Layanan')
                            ->options(fn (): array => self::serviceOptions())
                            ->searchable()
                            ->native(false)
                            ->helperText('Kosongkan untuk semua layanan.'),
                        Forms\Components\Select::make('match_point')
                            ->label('Titik yang dicek')
                            ->options([
                                'destination' => 'Tujuan / alamat antar',
                                'pickup' => 'Pickup / lokasi pembelian',
                                'either' => 'Pickup atau tujuan',
                                'both' => 'Pickup dan tujuan',
                            ])
                            ->default('destination')
                            ->native(false)
                            ->required(),
                        Forms\Components\Select::make('price_mode')
                            ->label('Mode tarif')
                            ->options([
                                'fixed' => 'Tarif tetap mengganti tarif dasar',
                                'extra' => 'Tambahan nominal ke tarif dasar',
                                'percent' => 'Tambahan persen dari tarif dasar',
                            ])
                            ->default('fixed')
                            ->native(false)
                            ->live()
                            ->required(),
                        Forms\Components\TextInput::make('amount')
                            ->label(fn (Forms\Get $get): string => $get('price_mode') === 'extra' ? 'Tambahan nominal' : 'Tarif tetap')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Forms\Get $get): bool => in_array($get('price_mode'), ['fixed', 'extra'], true))
                            ->required(fn (Forms\Get $get): bool => in_array($get('price_mode'), ['fixed', 'extra'], true)),
                        Forms\Components\TextInput::make('percent')
                            ->label('Tambahan persen')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->visible(fn (Forms\Get $get): bool => $get('price_mode') === 'percent')
                            ->required(fn (Forms\Get $get): bool => $get('price_mode') === 'percent'),
                        Forms\Components\TextInput::make('min_km')
                            ->numeric()
                            ->suffix('KM')
                            ->helperText('Opsional, kosongkan jika tidak ada batas jarak minimum.'),
                        Forms\Components\TextInput::make('max_km')
                            ->numeric()
                            ->suffix('KM')
                            ->helperText('Opsional, kosongkan jika tidak ada batas jarak maksimum.'),
                        Forms\Components\TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
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
                Tables\Columns\TextColumn::make('branch.display_name')
                    ->label('Cabang')
                    ->placeholder('Global')
                    ->sortable(),
                Tables\Columns\TextColumn::make('geofenceArea.name')
                    ->label('Zona')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->badge()
                    ->placeholder('Semua'),
                Tables\Columns\TextColumn::make('match_point')
                    ->label('Titik')
                    ->badge(),
                Tables\Columns\TextColumn::make('price_mode')
                    ->label('Mode')
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->money('IDR')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('percent')
                    ->suffix('%')
                    ->placeholder('-'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif'),
                Tables\Columns\TextColumn::make('priority')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Cabang')
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
            'index' => Pages\ListZonePricingRules::route('/'),
            'create' => Pages\CreateZonePricingRule::route('/create'),
            'edit' => Pages\EditZonePricingRule::route('/{record}/edit'),
        ];
    }

    public static function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }

    public static function geofenceOptions(?int $branchId = null): array
    {
        return GeofenceArea::query()
            ->with('branch')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (GeofenceArea $area): array => [$area->id => $area->name.' - '.$area->branch?->display_name])
            ->all();
    }

    public static function serviceOptions(): array
    {
        $services = Service::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Service $service): array => [$service->code ?: strtolower($service->name) => $service->name])
            ->all();

        return $services + [
            'ojek' => 'Ojek',
            'delivery' => 'Delivery',
            'belanja' => 'Belanja',
            'kurir' => 'Kurir',
            'gift_order' => 'Gift Order',
            'joker_mobil' => 'Joker Mobil',
        ];
    }
}
