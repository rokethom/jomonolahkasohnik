<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiAliasMapResource\Pages;
use App\Jobs\GenerateAiAliasMapsJob;
use App\Models\AiAliasMap;
use App\Models\Area;
use App\Models\Branch;
use App\Models\GeojsonRegion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AiAliasMapResource extends Resource
{
    protected static ?string $model = AiAliasMap::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI Alias Map';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true || Auth::user()?->hasPermission('manage_cms') === true;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(2)
            ->schema([
                Forms\Components\Section::make('Alias Map')
                    ->description('Pemetaan bahasa bebas customer ke area/GeoJSON yang sama. Dipakai sebelum provider maps.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('canonical_name')
                            ->label('Nama utama')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Kelurahan Mimbaan'),
                        Forms\Components\TagsInput::make('aliases')
                            ->label('Alias')
                            ->placeholder('mimbaan barat')
                            ->helperText('Contoh: dekat panji, sebelah kota, agel dekat pasar, mimbaan barat.')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('branch_id')
                            ->label('Cabang')
                            ->options(fn (): array => self::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Forms\Components\Select::make('area_id')
                            ->label('Area')
                            ->options(fn (): array => self::areaOptions())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Forms\Components\Select::make('geojson_region_id')
                            ->label('Target GeoJSON region')
                            ->options(fn (): array => self::geojsonRegionOptions())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required()
                            ->helperText('Koordinat pricing diambil dari centroid GeoJSON region ini.'),
                        Forms\Components\Select::make('source')
                            ->label('Source')
                            ->native(false)
                            ->default('manual')
                            ->options([
                                'manual' => 'Manual',
                                'generated' => 'Generated dari GeoJSON',
                                'history' => 'Learned dari histori order',
                            ]),
                        Forms\Components\TextInput::make('confidence')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(80)
                            ->required(),
                        Forms\Components\TextInput::make('priority')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['branch', 'area', 'geojsonRegion'])->latest('updated_at'))
            ->headerActions([
                Tables\Actions\Action::make('generate_aliases')
                    ->label('Auto Sync Generate')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Generate AI Alias Map?')
                    ->modalDescription('Sistem akan membuat alias awal dari GeoJSON Regions, lalu menambah alias dari manual order dan live edit harga yang cocok ke target GeoJSON. Alias manual tidak akan dihapus.')
                    ->action(function (): void {
                        GenerateAiAliasMapsJob::dispatch();

                        Notification::make()
                            ->title('AI Alias Map sedang diproses')
                            ->body('Generate berjalan di queue AI agar halaman tidak timeout. Cek AI Logs/Horizon untuk status.')
                            ->success()
                            ->send();
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('canonical_name')
                    ->label('Nama utama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('aliases')
                    ->label('Alias')
                    ->formatStateUsing(fn (mixed $state): string => collect($state ?? [])->take(4)->implode(', '))
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Cabang')
                    ->formatStateUsing(fn (AiAliasMap $record): string => $record->branch?->display_name ?? $record->geojsonRegion?->branch?->display_name ?? '-')
                    ->sortable(),
                Tables\Columns\TextColumn::make('geojsonRegion.name')
                    ->label('GeoJSON')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manual' => 'success',
                        'history' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('confidence')
                    ->label('Confidence')
                    ->sortable(),
                Tables\Columns\TextColumn::make('priority')
                    ->sortable(),
                Tables\Columns\TextColumn::make('hit_count')
                    ->label('Used')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktif'),
                Tables\Filters\SelectFilter::make('source')
                    ->options([
                        'manual' => 'Manual',
                        'generated' => 'Generated',
                        'history' => 'History',
                    ]),
            ])
            ->defaultPaginationPageOption(10)
            ->paginated([10, 25, 50])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->after(fn (): bool => Cache::flush()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->after(fn (): bool => Cache::flush()),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiAliasMaps::route('/'),
            'create' => Pages\CreateAiAliasMap::route('/create'),
            'edit' => Pages\EditAiAliasMap::route('/{record}/edit'),
        ];
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('branch_code')
            ->orderBy('name')
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

    private static function geojsonRegionOptions(): array
    {
        return GeojsonRegion::query()
            ->with(['branch', 'area'])
            ->orderBy('name')
            ->limit(1000)
            ->get()
            ->mapWithKeys(fn (GeojsonRegion $region): array => [
                $region->id => trim($region->name.' - '.($region->branch?->display_name ?? '-')),
            ])
            ->all();
    }
}
