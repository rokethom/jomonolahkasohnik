<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CoverageAreaResource\Pages;
use App\Models\Area;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CoverageAreaResource extends Resource
{
    protected static ?string $model = Area::class;
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Spatial Management';
    protected static ?string $navigationLabel = 'Coverage Area';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('branch_id')->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))->required()->searchable()->preload(),
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\TextInput::make('code')->required()->maxLength(40),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('branch.name')->label('Branch')->sortable(),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCoverageAreas::route('/'), 'create' => Pages\CreateCoverageArea::route('/create'), 'edit' => Pages\EditCoverageArea::route('/{record}/edit')];
    }
}
