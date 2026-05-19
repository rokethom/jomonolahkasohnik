<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ChatStickerResource\Pages;
use App\Models\ChatSticker;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChatStickerResource extends Resource
{
    protected static ?string $model = ChatSticker::class;

    protected static ?string $navigationIcon = 'heroicon-o-face-smile';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Sticker Chat CMS';

    protected static ?int $navigationSort = 7;

    public static function shouldRegisterNavigation(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canEdit($record): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Sticker Chat')
                ->description('Sticker aktif akan tampil di chat operator, eksekutor, dan manajemen.')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('image_path')
                        ->label('File sticker')
                        ->disk('public')
                        ->directory('chat/stickers')
                        ->image()
                        ->imagePreviewHeight('140')
                        ->acceptedFileTypes(['image/png', 'image/webp', 'image/gif'])
                        ->maxSize(1024)
                        ->downloadable()
                        ->openable()
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('name')
                        ->label('Nama sticker')
                        ->required()
                        ->maxLength(80),
                    Forms\Components\TextInput::make('category')
                        ->label('Kategori')
                        ->default('umum')
                        ->required()
                        ->maxLength(40),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Urutan')
                        ->numeric()
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
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('Sticker')
                    ->disk('public')
                    ->height(56)
                    ->width(56),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Update')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->visible(fn (): bool => auth()->user()?->role === UserRole::Admin),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->visible(fn (): bool => auth()->user()?->role === UserRole::Admin),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChatStickers::route('/'),
            'create' => Pages\CreateChatSticker::route('/create'),
            'edit' => Pages\EditChatSticker::route('/{record}/edit'),
        ];
    }
}
