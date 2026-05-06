<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserDeviceTokenResource\Pages;
use App\Enums\UserRole;
use App\Models\UserDeviceToken;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserDeviceTokenResource extends Resource
{
    protected static ?string $model = UserDeviceToken::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'Device Tokens';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 7;

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('create_user') === true
            || Auth::user()?->hasPermission('monitor_live_chat') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('user.role')
                    ->label('Role')
                    ->formatStateUsing(fn (UserRole|string|null $state): string => $state instanceof UserRole ? $state->label() : (string) ($state ?: '-'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('app')
                    ->badge()
                    ->placeholder('-')
                    ->color(fn (?string $state): string => match ($state) {
                        'driver' => 'success',
                        'customer' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('platform')
                    ->badge()
                    ->placeholder('web'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('token')
                    ->label('Token')
                    ->formatStateUsing(fn (?string $state): string => self::maskToken($state))
                    ->copyable()
                    ->copyMessage('Token disalin')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user_agent')
                    ->limit(45)
                    ->tooltip(fn (UserDeviceToken $record): ?string => $record->user_agent)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('app')
                    ->options([
                        'customer' => 'Customer',
                        'driver' => 'Driver',
                    ])
                    ->native(false),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\Action::make('testPush')
                    ->label('Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (UserDeviceToken $record): void {
                        $result = app(PushNotificationService::class)->send(
                            $record->token,
                            'Tes Notifikasi JOJO',
                            'Device token ini berhasil menerima request FCM dari backend.',
                            ['type' => 'test_push', 'device_token_id' => $record->id],
                        );

                        if ($result['success'] ?? false) {
                            $record->update(['is_active' => true, 'last_seen_at' => now()]);
                            Notification::make()->title('Test push terkirim')->success()->send();
                            return;
                        }

                        Notification::make()
                            ->title('Test push gagal')
                            ->body((string) ($result['message'] ?? json_encode($result['response'] ?? $result)))
                            ->danger()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (UserDeviceToken $record): bool => $record->is_active)
                    ->action(function (UserDeviceToken $record): void {
                        $record->update(['is_active' => false]);
                        Notification::make()->title('Device token dimatikan')->success()->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->poll('10s')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserDeviceTokens::route('/'),
        ];
    }

    private static function maskToken(?string $token): string
    {
        if (! filled($token)) {
            return '-';
        }

        return substr($token, 0, 18).'...'.substr($token, -10).' ('.strlen($token).')';
    }
}
