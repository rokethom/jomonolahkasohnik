<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ZonePricingRuleResource\Pages;
use App\Filament\Support\PricingCsvTableActions;
use App\Models\Branch;
use App\Models\GeofenceArea;
use App\Models\Service;
use App\Models\ZonePricingRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
            ->schema([
                Forms\Components\Section::make('Zone Pricing Rule')
                    ->description('Atur tarif khusus berdasarkan area geofence. Rule ini aktif setelah tarif dasar/ring terbaca, lalu sistem mengecek apakah titik pickup atau tujuan masuk zona yang dipilih.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('flow_reference')
                            ->label('Cara kerja singkat')
                            ->content('Flow: order masuk -> sistem deteksi cabang dari geofence tujuan/pickup -> hitung tarif dasar/ring -> cek Zone Pricing aktif -> tarif fixed/extra/percent diterapkan -> hasil tampil ke customer dan driver.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('name')
                            ->label('Nama rule')
                            ->helperText('Contoh: STB Panarukan malam, Roxy radius dekat, Area RS tambah jasa.')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('branch_id')
                            ->label('Cabang')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->default(fn (): ?int => self::actorCanManageGlobalPricing() ? null : self::scopedBranchId())
                            ->disabled(fn (): bool => ! self::actorCanManageGlobalPricing())
                            ->dehydrated()
                            ->required(fn (): bool => ! self::actorCanManageGlobalPricing())
                            ->helperText(fn (): string => self::actorCanManageGlobalPricing()
                                ? 'Kosongkan untuk rule global. Jika diisi, daftar geofence akan mengikuti cabang.'
                                : 'Role cabang hanya boleh mengatur zone pricing cabangnya sendiri.'),
                        Forms\Components\Select::make('geofence_area_id')
                            ->label('Zona / Geofence')
                            ->options(fn (Forms\Get $get): array => self::geofenceOptions($get('branch_id') ? (int) $get('branch_id') : null))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->helperText('Zona ini diambil dari Geofence Area. Jika geofence berbentuk polygon, titik harus berada di dalam polygon. Jika circle, titik harus berada dalam radius.')
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
                            ->helperText('Pilih Tujuan untuk tarif berdasarkan alamat antar. Pilih Pickup untuk lokasi pembelian/jemput. Either artinya salah satu titik cukup masuk zona. Both artinya pickup dan tujuan wajib masuk zona.')
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
                            ->helperText('Fixed mengganti tarif dasar. Extra menambah nominal ke tarif dasar. Percent menambah persen dari tarif dasar.')
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
                            ->helperText('Opsional. Rule hanya berlaku jika jarak order minimal angka ini. Kosongkan agar tidak dibatasi.'),
                        Forms\Components\TextInput::make('max_km')
                            ->numeric()
                            ->suffix('KM')
                            ->helperText('Opsional. Rule hanya berlaku jika jarak order maksimal angka ini. Kosongkan untuk tanpa batas atas.'),
                        Forms\Components\TextInput::make('priority')
                            ->numeric()
                            ->default(0)
                            ->helperText('Jika ada beberapa rule yang cocok, priority terbesar akan dipakai lebih dulu.')
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Nonaktifkan jika rule ingin disimpan tetapi belum dipakai sistem.')
                            ->default(true),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->helperText('Catatan internal admin, tidak tampil ke customer.')
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('example_reference')
                            ->label('Contoh')
                            ->content('Contoh fixed: Zona Panarukan untuk delivery -> tarif tetap Rp 12.000. Contoh extra: Zona depan Roxy -> tambah Rp 3.000. Contoh percent: Zona jam/rute khusus -> tambah 30% dari tarif dasar.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->headerActions(PricingCsvTableActions::make('zone'))
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
            ->when(! self::actorCanManageGlobalPricing(), fn (Builder $query) => $query->whereKey(self::scopedBranchId() ?? 0))
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }

    public static function geofenceOptions(?int $branchId = null): array
    {
        if (! self::actorCanManageGlobalPricing()) {
            $branchId = self::scopedBranchId();
        }

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

    public static function normalizeScopedData(array $data): array
    {
        if (! static::actorCanManageGlobalPricing()) {
            $data['branch_id'] = static::scopedBranchId();
        }

        return $data;
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

    private static function recordInScope(?ZonePricingRule $record): bool
    {
        if (! $record) {
            return false;
        }

        return static::actorCanManageGlobalPricing()
            || ((int) $record->branch_id === (int) static::scopedBranchId() && $record->branch_id !== null);
    }
}
