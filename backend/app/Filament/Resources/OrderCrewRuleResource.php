<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderCrewRuleResource\Pages;
use App\Models\OrderCrewRule;
use App\Models\Service;
use App\Services\OrderCrewDecisionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class OrderCrewRuleResource extends Resource
{
    protected static ?string $model = OrderCrewRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Crew Decision Rules';

    protected static ?int $navigationSort = 4;

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();
        $role = $user?->role;
        $roleValue = is_object($role) && isset($role->value) ? $role->value : $role;

        return $user?->hasPermission('manage_manual_order') === true
            || $user?->hasPermission('manage_system_settings') === true
            || $roleValue === 'admin';
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('AI Decision Engine / Crew Rule')
                ->description('Rule ini membuat order multi-crew tanpa ubah kode. Sistem membaca keyword pada order, lalu menambahkan slot helper setelah rider menerima order.')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('flow')
                        ->label('Flow operasional')
                        ->content('Order masuk -> rider menerima order -> sistem membuka slot helper -> helper menerima -> order aktif dengan rider dan helper. Customer tetap melihat 1 order.')
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('guide')
                        ->label('Cara pakai CMS')
                        ->content('Isi keyword pemicu, pilih layanan yang berlaku, lalu tentukan label helper. Contoh selain tart: keyword "parcel besar" bisa memakai label "Helper barang besar". Admin cukup tambah rule baru di sini tanpa ubah kode.')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('name')
                        ->label('Nama rule')
                        ->placeholder('Kue tart butuh helper')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Textarea::make('keywords')
                        ->label('Keyword pemicu')
                        ->placeholder('kue tart, tart, cake fragile')
                        ->helperText('Pisahkan dengan koma. Contoh: kue tart, tart. Sistem match kata utuh agar tidak terlalu liar.')
                        ->required()
                        ->rows(3),
                    Forms\Components\Select::make('service_scopes')
                        ->label('Layanan yang dicek')
                        ->multiple()
                        ->options(fn (): array => self::serviceOptions())
                        ->placeholder('Semua layanan')
                        ->helperText('Kosongkan untuk semua layanan. Isi jika rule hanya berlaku pada delivery/belanja/gift order.')
                        ->native(false),
                    Forms\Components\Toggle::make('requires_helper')
                        ->label('Butuh helper')
                        ->default(true)
                        ->helperText('Jika aktif, rider accept dulu lalu sistem mencari helper.'),
                    Forms\Components\TextInput::make('helper_role')
                        ->label('Kode role helper')
                        ->default('helper')
                        ->required()
                        ->rule('alpha_dash')
                        ->helperText('Pakai huruf/angka/underscore/dash saja, contoh: helper atau cake_helper.')
                        ->maxLength(40),
                    Forms\Components\TextInput::make('helper_label')
                        ->label('Label tampil')
                        ->default('Helper')
                        ->required()
                        ->maxLength(80),
                    Forms\Components\TextInput::make('helper_service_charge')
                        ->label('Jasa helper fallback')
                        ->numeric()
                        ->prefix('Rp')
                        ->default(0)
                        ->helperText('Fallback lama. Untuk rule baru gunakan rumus harga helper di bawah.'),
                    Forms\Components\TextInput::make('helper_base_distance_km')
                        ->label('Jarak dasar helper')
                        ->numeric()
                        ->suffix('KM')
                        ->default(10)
                        ->helperText('Jika jarak order 0 KM sampai nilai ini, jasa helper memakai harga dasar.'),
                    Forms\Components\TextInput::make('helper_base_price')
                        ->label('Harga dasar helper')
                        ->numeric()
                        ->prefix('Rp')
                        ->default(6000)
                        ->helperText('Contoh rule kue tart: 0-10 KM jasa helper Rp 6.000.'),
                    Forms\Components\TextInput::make('helper_over_distance_percent')
                        ->label('Persen jika lewat jarak dasar')
                        ->numeric()
                        ->suffix('%')
                        ->default(50)
                        ->helperText('Jika jarak > jarak dasar, jasa helper = persen ini dari harga jasa driver. Contoh 50% dari tarif driver.'),
                    Forms\Components\TextInput::make('priority')
                        ->numeric()
                        ->default(0)
                        ->helperText('Angka lebih besar dicek lebih dulu jika ada keyword yang mirip.'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                    Forms\Components\Textarea::make('description')
                        ->label('Penjelasan internal')
                        ->placeholder('Contoh: Kue tart fragile, helper membantu pegang barang agar aman.')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('keywords')->wrap()->limit(48)->searchable(),
                Tables\Columns\TextColumn::make('helper_label')->badge(),
                Tables\Columns\TextColumn::make('helper_base_price')->label('0-10 KM')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('helper_over_distance_percent')->label('> KM')->suffix('%')->sortable(),
                Tables\Columns\IconColumn::make('requires_helper')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('priority')->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListOrderCrewRules::route('/'),
            'create' => Pages\CreateOrderCrewRule::route('/create'),
            'edit' => Pages\EditOrderCrewRule::route('/{record}/edit'),
        ];
    }

    protected static function afterSave(): void
    {
        app(OrderCrewDecisionService::class)->clearCache();
    }

    private static function serviceOptions(): array
    {
        return Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Service $service): array => [strtolower($service->getAttribute('service_type') ?: $service->code) => "{$service->name} ({$service->code})"])
            ->all() + ['all' => 'Semua layanan'];
    }
}
