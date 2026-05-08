<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\InternalChatRoomResource\Pages;
use App\Models\InternalChatRoom;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InternalChatRoomResource extends Resource
{
    protected static ?string $model = InternalChatRoom::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Internal Chat';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role instanceof UserRole
            && auth()->user()?->role->isStaff();
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }

    public static function canEdit($record): bool
    {
        return static::canCreate();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'latestMessage.sender'])->withCount('participants');
        $user = auth()->user();

        if (! $user || in_array($user->role, [UserRole::Admin, UserRole::GM], true)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('type', 'global')
                ->orWhere(function (Builder $query) use ($user): void {
                    $query->where('type', 'branch')->where('branch_id', $user->branch_id);
                })
                ->orWhereHas('participants', fn (Builder $query) => $query->whereKey($user->id));
        });
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(80),
            Forms\Components\Select::make('type')
                ->options([
                    'global' => 'Global',
                    'branch' => 'Cabang / Area',
                    'private' => 'Private',
                ])
                ->default('branch')
                ->required(),
            Forms\Components\Select::make('branch_id')
                ->relationship('branch', 'name')
                ->searchable()
                ->preload()
                ->visible(fn (Forms\Get $get): bool => $get('type') !== 'global'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->placeholder('Global')->searchable(),
                Tables\Columns\TextColumn::make('branch.area')->label('Area')->placeholder('-'),
                Tables\Columns\TextColumn::make('participants_count')->label('Users')->badge(),
                Tables\Columns\TextColumn::make('latestMessage.message')->label('Last Message')->limit(48)->placeholder('Belum ada pesan'),
                Tables\Columns\TextColumn::make('latestMessage.sender.name')->label('Last Sender')->placeholder('-'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options([
                    'global' => 'Global',
                    'branch' => 'Cabang / Area',
                    'private' => 'Private',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn (): bool => in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true)),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInternalChatRooms::route('/'),
            'create' => Pages\CreateInternalChatRoom::route('/create'),
            'view' => Pages\ViewInternalChatRoom::route('/{record}'),
            'edit' => Pages\EditInternalChatRoom::route('/{record}/edit'),
        ];
    }
}
