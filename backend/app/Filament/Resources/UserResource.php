<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Management';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('create_user') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('User Management')
                    ->extraAttributes(['class' => 'rounded-xl shadow-xl'])
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Info User')
                            ->icon('heroicon-o-user-circle')
                            ->schema([
                                Forms\Components\Section::make('Identity')
                                    ->columns(2)
                                    ->extraAttributes(['class' => 'rounded-xl shadow-xl transition hover:shadow-2xl'])
                                    ->schema([
                                        Forms\Components\TextInput::make('username')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true),
                                        Forms\Components\TextInput::make('name')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('email')
                                            ->email()
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true),
                                        Forms\Components\TextInput::make('phone')
                                            ->tel()
                                            ->maxLength(30),
                                        Forms\Components\Textarea::make('address')
                                            ->label('Alamat')
                                            ->maxLength(500)
                                            ->rows(3)
                                            ->columnSpanFull()
                                            ->helperText('Alamat profil customer/driver. Dipakai untuk auto-fill order dan referensi operator.'),
                                        Forms\Components\TextInput::make('password')
                                            ->label('Password')
                                            ->password()
                                            ->minLength(8)
                                            ->maxLength(255)
                                            ->afterStateHydrated(function (Forms\Components\TextInput $component): void {
                                                $component->state(null);
                                            })
                                            ->dehydrated(fn (?string $state): bool => filled($state))
                                            ->helperText('Isi untuk set password manual. Kosongkan saat edit jika tidak ingin mengubah password.'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Role & Branch')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Forms\Components\Section::make('Access')
                                    ->columns(2)
                                    ->extraAttributes(['class' => 'rounded-xl shadow-xl transition hover:shadow-2xl'])
                                    ->schema([
                                        Forms\Components\Select::make('role')
                                            ->options(fn (): array => self::roleOptions())
                                            ->required()
                                            ->native(false)
                                            ->live(),
                                        Forms\Components\Select::make('branch_id')
                                            ->label('Branch')
                                            ->options(fn (): array => self::branchOptions())
                                            ->searchable()
                                            ->native(false),
                                        Forms\Components\Toggle::make('is_staff')
                                            ->label('Staff Access')
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText('Otomatis berdasarkan role.'),
                                        Forms\Components\TextInput::make('driver_bansos_amount')
                                            ->label('Bansos Driver')
                                            ->numeric()
                                            ->minValue(0)
                                            ->prefix('Rp')
                                            ->visible(fn (Forms\Get $get): bool => $get('role') === UserRole::Driver->value)
                                            ->afterStateHydrated(function (Forms\Components\TextInput $component, ?User $record): void {
                                                $component->state($record?->driver?->bansos_amount);
                                            })
                                            ->helperText('Kosongkan untuk memakai bansos otomatis berdasarkan area.'),
                                        Forms\Components\Toggle::make('driver_bpjs_jht_enabled')
                                            ->label('JHT BPJS Ketenagakerjaan')
                                            ->default(true)
                                            ->visible(fn (Forms\Get $get): bool => $get('role') === UserRole::Driver->value)
                                            ->afterStateHydrated(function (Forms\Components\Toggle $component, ?User $record): void {
                                                $component->state($record?->driver?->bpjs_jht_enabled ?? true);
                                            })
                                            ->helperText('Aktif berarti tagihan JHT ikut masuk setoran driver.'),
                                    ]),
                            ]),
                        Forms\Components\Tabs\Tab::make('Status')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Forms\Components\Section::make('Account Status')
                                    ->columns(2)
                                    ->extraAttributes(['class' => 'rounded-xl shadow-xl transition hover:shadow-2xl'])
                                    ->schema([
                                        Forms\Components\Toggle::make('is_active')
                                            ->default(true),
                                        Forms\Components\Toggle::make('is_suspended')
                                            ->live()
                                            ->default(false),
                                        Forms\Components\Textarea::make('suspension_reason')
                                            ->visible(fn (Forms\Get $get): bool => (bool) $get('is_suspended'))
                                            ->columnSpanFull(),
                                        Forms\Components\DateTimePicker::make('suspended_until')
                                            ->visible(fn (Forms\Get $get): bool => (bool) $get('is_suspended')),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (UserRole|string $state): string => ($state instanceof UserRole ? $state : UserRole::from($state))->label())
                    ->color(fn (UserRole|string $state): string => ($state instanceof UserRole ? $state : UserRole::from($state))->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Branch')
                    ->formatStateUsing(fn (User $record): string => $record->branch?->display_name ?? 'No branch')
                    ->placeholder('Global')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => $record->is_suspended ? 'suspended' : ($record->is_active ? 'active' : 'inactive'))
                    ->color(fn (string $state): string => match ($state) {
                        'suspended' => 'danger',
                        'active' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('driver.is_available')
                    ->label('Driver Active')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Alamat')
                    ->searchable()
                    ->limit(36)
                    ->tooltip(fn (User $record): ?string => $record->address)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Visible role')
                    ->placeholder('All visible roles')
                    ->native(false)
                    ->options(fn (): array => self::roleOptions()),
                Tables\Filters\TernaryFilter::make('is_suspended')
                    ->label('Suspended'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn (): array => self::branchOptions())
                    ->searchable(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->actions([
                Tables\Actions\Action::make('resetPassword')
                    ->label('Reset Password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => Gate::allows('resetPassword', $record))
                    ->action(function (User $record): void {
                        $password = self::generatePassword();
                        $record->update(['password' => $password]);

                        Notification::make()
                            ->title('Password berhasil di-reset')
                            ->body("Password baru {$record->username}: {$password}")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\Action::make('resetToken')
                    ->label('Reset Token')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Reset token login user?')
                    ->modalDescription('Semua sesi login dan token push user ini akan dimatikan. User perlu login ulang.')
                    ->visible(fn (User $record): bool => Gate::allows('update', $record) && ! Auth::user()?->is($record))
                    ->action(function (User $record): void {
                        $record->tokens()->delete();
                        $record->deviceTokens()->update(['is_active' => false]);

                        Notification::make()
                            ->title('Token user berhasil direset')
                            ->body("Minta {$record->username} login ulang.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'driver']);
        $actor = Auth::user();
        $role = $actor?->role instanceof UserRole ? $actor->role : UserRole::tryFrom((string) $actor?->role);

        return match ($role) {
            UserRole::Admin => $query,
            UserRole::HRD => $query->whereIn('role', collect(UserRole::HRD->assignableRoles())->pluck('value')->all()),
            UserRole::SPV => $query->whereIn('role', collect(UserRole::SPV->assignableRoles())->pluck('value')->all()),
            UserRole::GM,
            UserRole::Manager => $query->where('role', '!=', UserRole::Admin->value),
            default => $query->whereKey($actor?->id),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function roleOptions(): array
    {
        $actor = Auth::user();
        $role = $actor?->role instanceof UserRole ? $actor->role : UserRole::tryFrom((string) $actor?->role);

        return UserRole::options($role);
    }

    public static function generatePassword(): string
    {
        return Str::upper(Str::random(2)).Str::random(6).random_int(10, 99).'!';
    }

    private static function branchOptions(): array
    {
        return Branch::query()
            ->orderBy('name')
            ->orderBy('area')
            ->get()
            ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
            ->all();
    }
}
