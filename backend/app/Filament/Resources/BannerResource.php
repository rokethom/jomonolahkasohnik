<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BannerResource\Pages;
use App\Models\Banner;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Home CMS';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Banner Slider')
                ->columns(2)
                ->schema([
                    SpatieMediaLibraryFileUpload::make('image')
                        ->collection('image')
                        ->image()
                        ->imagePreviewHeight('160')
                        ->maxSize(1024)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->downloadable()
                        ->openable()
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('title')
                        ->live(debounce: 300)
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('link')
                        ->url()
                        ->maxLength(255),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                    Forms\Components\TextInput::make('order')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Forms\Components\DateTimePicker::make('start_date')
                        ->seconds(false),
                    Forms\Components\DateTimePicker::make('end_date')
                        ->seconds(false)
                        ->afterOrEqual('start_date'),
                    Forms\Components\View::make('filament.forms.components.home-cms-placement-preview')
                        ->viewData(['kind' => 'banner'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('order')
            ->defaultSort('order')
            ->columns([
                SpatieMediaLibraryImageColumn::make('image')
                    ->collection('image')
                    ->conversion('thumb')
                    ->label('Preview')
                    ->height(64),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (Banner $record): string => $record->is_active ? 'active' : 'inactive')
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('order')
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->dateTime()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->dateTime()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
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
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
