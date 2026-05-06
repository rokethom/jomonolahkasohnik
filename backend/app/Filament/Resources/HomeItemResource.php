<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HomeItemResource\Pages;
use App\Models\HomeItem;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Table;

class HomeItemResource extends Resource
{
    protected static ?string $model = HomeItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Home CMS';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Home Item')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('section_id')
                        ->relationship('section', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\TextInput::make('title')
                        ->live(debounce: 300)
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('subtitle')
                        ->live(debounce: 300)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('link')
                        ->maxLength(255),
                    SpatieMediaLibraryFileUpload::make('image')
                        ->collection('image')
                        ->image()
                        ->imagePreviewHeight('140')
                        ->maxSize(1024)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->downloadable()
                        ->openable()
                        ->columnSpan(1),
                    SpatieMediaLibraryFileUpload::make('icon')
                        ->collection('icon')
                        ->image()
                        ->imagePreviewHeight('96')
                        ->maxSize(512)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->downloadable()
                        ->openable()
                        ->columnSpan(1),
                    Forms\Components\KeyValue::make('extra_data')
                        ->keyLabel('Key')
                        ->valueLabel('Value')
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\TextInput::make('order')
                        ->numeric()
                        ->default(0)
                        ->required(),
                    Forms\Components\DateTimePicker::make('start_date')
                        ->label('Tampil mulai')
                        ->seconds(false),
                    Forms\Components\DateTimePicker::make('end_date')
                        ->label('Tampil sampai')
                        ->seconds(false)
                        ->afterOrEqual('start_date')
                        ->helperText('Jika tanggal selesai lewat, item otomatis tidak tampil dan akan dibersihkan oleh scheduler.'),
                    Forms\Components\View::make('filament.forms.components.home-cms-placement-preview')
                        ->viewData(['kind' => 'item'])
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
                    ->height(54),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('section.name')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subtitle')
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('order')
                    ->sortable(),
                Tables\Columns\TextColumn::make('start_date')
                    ->label('Start')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('end_date')
                    ->label('End')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('section_id')
                    ->relationship('section', 'name')
                    ->label('Section'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\Filter::make('expired')
                    ->label('Expired')
                    ->query(fn ($query) => $query->whereNotNull('end_date')->where('end_date', '<', now())),
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
            'index' => Pages\ListHomeItems::route('/'),
            'create' => Pages\CreateHomeItem::route('/create'),
            'edit' => Pages\EditHomeItem::route('/{record}/edit'),
        ];
    }
}
