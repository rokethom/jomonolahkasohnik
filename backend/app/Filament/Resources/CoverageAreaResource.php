<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CoverageAreaResource\Pages;
use App\Models\Area;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CoverageAreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Location';

    protected static ?string $navigationLabel = 'Area Layanan';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('edit_tarif') === true || Auth::user()?->hasPermission('manage_cms') === true;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('branch_id')
                ->label('Cabang')
                ->options(fn (): array => self::branchOptions())
                ->required()
                ->searchable()
                ->preload()
                ->native(false),
            Forms\Components\TextInput::make('name')
                ->label('Nama area')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('code')
                ->label('Kode')
                ->required()
                ->maxLength(40),
            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('branch_id')
                ->label('Cabang')
                ->formatStateUsing(fn (Area $record): string => $record->branch?->display_name ?? '-')
                ->sortable(),
            Tables\Columns\TextColumn::make('name')
                ->label('Area')
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('code')
                ->searchable(),
            Tables\Columns\IconColumn::make('is_active')
                ->label('Aktif')
                ->boolean(),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoverageAreas::route('/'),
            'create' => Pages\CreateCoverageArea::route('/create'),
            'edit' => Pages\EditCoverageArea::route('/{record}/edit'),
        ];
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->operationalAreas()
            ->orderBy('branch_code')
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }
}
