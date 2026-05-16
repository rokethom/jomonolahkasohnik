<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Location';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Branch')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('parent_branch_id')
                            ->label('Parent Branch Kota/Kab')
                            ->helperText('Kosongkan jika ini level kota/kab. Isi parent jika ini area/kecamatan operasional.')
                            ->options(fn (): array => Branch::query()
                                ->regencies()
                                ->orderBy('branch_code')
                                ->get()
                                ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Forms\Components\TextInput::make('branch_code')
                            ->label('Kode branch unik')
                            ->placeholder('STB / STBKT / STBASB')
                            ->helperText('Branch kota/kab contoh STB. Area operasional contoh STBKT, STBASB, STBBSK.')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? strtoupper(trim($state)) : null),
                        Forms\Components\TextInput::make('name')
                            ->label('Kabupaten / Kota')
                            ->helperText('Contoh: Situbondo, Bondowoso, Probolinggo.')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('area')
                            ->label('Nama area/kecamatan')
                            ->helperText('Kosongkan untuk parent kota/kab. Contoh area: Asembagus, Kota, Besuki.')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('latitude')
                            ->numeric()
                            ->required()
                            ->live(onBlur: true)
                            ->extraInputAttributes(['id' => 'branch_latitude']),
                        Forms\Components\TextInput::make('longitude')
                            ->numeric()
                            ->required()
                            ->live(onBlur: true)
                            ->extraInputAttributes(['id' => 'branch_longitude']),
                        Forms\Components\TextInput::make('radius_km')
                            ->label('Radius default awal')
                            ->numeric()
                            ->minValue(0.1)
                            ->default(5)
                            ->suffix('KM')
                            ->helperText('Bukan radius validasi utama. Radius operasional aktif diambil dari Geofence Area.')
                            ->required(),
                        Forms\Components\View::make('filament.forms.components.branch-map-picker')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Titik Nol Pricing')
                    ->columns(3)
                    ->description('Acuan jarak harga. Jika kosong, sistem memakai lat/lng cabang lama sebagai fallback.')
                    ->schema([
                        Forms\Components\TextInput::make('pricing_origin_name')
                            ->label('Nama titik nol')
                            ->placeholder('Alun-alun Situbondo / Pasar Paiton')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('pricing_origin_latitude')
                            ->label('Lat titik nol')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('pricing_origin_longitude')
                            ->label('Lng titik nol')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->live(onBlur: true),
                        Forms\Components\Placeholder::make('pricing_origin_hint')
                            ->label('Cara pakai')
                            ->content('Isi titik nol per area: STBKT = Alun-alun Situbondo, STBASB = Taman Kota Asembagus, STBBSK = Alun-alun Besuki, dan seterusnya.')
                            ->columnSpan(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch_code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->badge(),
                Tables\Columns\TextColumn::make('parent.branch_code')
                    ->label('Parent')
                    ->badge()
                    ->placeholder('Kota/Kab')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Kab/Kota')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('area')
                    ->label('Area')
                    ->searchable()
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('latitude')
                    ->sortable(),
                Tables\Columns\TextColumn::make('longitude')
                    ->sortable(),
                Tables\Columns\TextColumn::make('radius_km')
                    ->label('Default KM')
                    ->suffix(' KM')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pricing_origin_name')
                    ->label('Titik Nol')
                    ->placeholder('Fallback lat/lng cabang')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pricing_origin_latitude')
                    ->label('Lat Nol')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pricing_origin_longitude')
                    ->label('Lng Nol')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('geofence_areas_count')
                    ->state(fn (Branch $record): string => $record->geofenceAreas
                        ->pluck('name')
                        ->filter()
                        ->join(', ') ?: '-')
                    ->label('Geofences')
                    ->wrap(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
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
            ->defaultSort('branch_code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('geofenceAreas');
    }
}
