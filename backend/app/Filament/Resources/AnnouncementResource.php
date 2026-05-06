<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Home CMS';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Announcement')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->live(debounce: 300)
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\RichEditor::make('content')
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\DateTimePicker::make('start_date')
                        ->seconds(false),
                    Forms\Components\DateTimePicker::make('end_date')
                        ->seconds(false)
                        ->afterOrEqual('start_date'),
                    Forms\Components\View::make('filament.forms.components.home-cms-placement-preview')
                        ->viewData(['kind' => 'announcement'])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('content')
                    ->html()
                    ->limit(50),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->state(fn (Announcement $record): string => $record->is_active ? 'active' : 'inactive')
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('start_date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('end_date')
                    ->dateTime()
                    ->sortable()
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
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
