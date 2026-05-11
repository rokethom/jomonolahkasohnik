<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\RingPricingRuleResource\Pages;
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
                            ->required(fn (): bool => ! self::actorCanManageGlobalPricing())
                            ->helperText(fn (): string => self::actorCanManageGlobalPricing()
                                ? 'Kosongkan jika berlaku global.'
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
                        Forms\Components\TextInput::make('pickup_area')
                            ->label('Asal Area')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Pasar Kampung Asembagus'),
                        Forms\Components\TextInput::make('destination_area')
                            ->label('Tujuan Area')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Pelabuhan Jangkar'),
                        Forms\Components\TagsInput::make('pickup_aliases')
                            ->label('Alias Asal')
                            ->placeholder('pasar asembagus')
                            ->helperText('Tambahkan variasi nama lokasi yang sering ditulis operator/customer.'),
                        Forms\Components\TagsInput::make('destination_aliases')
                            ->label('Alias Tujuan')
                            ->placeholder('p jangkar'),
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

        return $data;
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
