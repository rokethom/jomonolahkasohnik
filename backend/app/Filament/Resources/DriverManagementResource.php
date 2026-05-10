<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\DriverManagementResource\Pages;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Models\Service;
use App\Services\DriverManagementCsvService;
use App\Services\DriverSuspendService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DriverManagementResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Driver Management';

    protected static ?string $navigationGroup = 'Management';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user?->hasPermission('suspend_driver') === true
            || $user?->hasPermission('unsuspend_driver') === true;
    }

    public static function canViewAny(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['branch', 'driver.suspensions'])
            ->where('role', UserRole::Driver->value);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('profile_photo_path')
                    ->label('Foto')
                    ->disk('public')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Driver')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $record): string => trim(implode(' | ', array_filter([
                        $record->username,
                        $record->phone,
                    ]))) ?: '-')
                    ->wrap(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('Branch')
                    ->getStateUsing(fn (User $record): string => $record->branch?->display_name ?? 'Global')
                    ->description(fn (User $record): ?string => $record->branch?->area ? 'Area: '.$record->branch->area : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('branch', fn (Builder $query): Builder => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('area', 'like', "%{$search}%")))
                    ->sortable(),
                Tables\Columns\TextColumn::make('driver.vehicle_type')
                    ->label('Kendaraan')
                    ->badge()
                    ->default('motor')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('driver.vehicle_seat_rows')
                    ->label('Seat')
                    ->formatStateUsing(fn ($state, User $record): string => $record->driver?->vehicle_type === 'mobil' ? (($state ?: 2).' baris') : '-')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('driver.is_ladies_driver')
                    ->label('Ladies')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('driver.allowed_service_types')
                    ->label('Layanan')
                    ->formatStateUsing(fn (?array $state): string => $state ? implode(', ', $state) : 'Semua layanan')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('driver.status')
                    ->label('Driver Status')
                    ->badge()
                    ->default('active')
                    ->color(fn (?string $state): string => match ($state) {
                        'suspended_unpaid' => 'warning',
                        'suspended' => 'danger',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('driver.suspended_until')
                    ->label('Until')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('google_auth_summary')
                    ->label('Google Auth')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => self::googleAuthState($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Suspended' => 'danger',
                        'Locked' => 'warning',
                        'Bound' => 'success',
                        default => 'gray',
                    })
                    ->description(fn (User $record): string => self::driverGoogleEmail($record) ?: '-'),
                Tables\Columns\TextColumn::make('google_email_display')
                    ->label('Email Google')
                    ->getStateUsing(fn (User $record): string => self::driverGoogleEmail($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('email', 'like', "%{$search}%"))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('driver.last_login_at')
                    ->label('Login Terakhir')
                    ->dateTime()
                    ->placeholder('-')
                    ->description(fn (User $record): string => $record->driver?->last_login_device ? 'Device: '.$record->driver->last_login_device : '')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('driver.last_login_device')
                    ->label('Device')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('driver.auth_failed_attempts')
                    ->label('Fail')
                    ->numeric()
                    ->badge()
                    ->color(fn (?int $state): string => ($state ?? 0) >= 5 ? 'danger' : (($state ?? 0) > 0 ? 'warning' : 'gray'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('driver.auth_locked_until')
                    ->label('Auth Lock')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('driver.auth_suspended_at')
                    ->label('Auth Suspend')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->driver?->auth_suspended_at !== null)
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('driver.oper_handle_count')
                    ->label('Oper')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('driver_status')
                    ->label('Driver Status')
                    ->options([
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'suspended_unpaid' => 'Suspend belum bayar setoran',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('driver', fn (Builder $query): Builder => $query->where('status', $data['value']))
                        : $query),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Cabang / Area')
                    ->options(fn (): array => Branch::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Branch $branch): array => [$branch->id => $branch->display_name])
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('branch_id', $data['value'])
                        : $query),
                Tables\Filters\SelectFilter::make('google_auth')
                    ->label('Google Auth')
                    ->options([
                        'bound' => 'Sudah bind',
                        'unbound' => 'Belum bind',
                        'locked' => 'Terkunci sementara',
                        'suspended' => 'Auth disuspend',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'bound' => $query->whereHas('driver', fn (Builder $query): Builder => $query->whereNotNull('google_id')),
                            'unbound' => $query->whereHas('driver', fn (Builder $query): Builder => $query->whereNull('google_id')),
                            'locked' => $query->whereHas('driver', fn (Builder $query): Builder => $query->where('auth_locked_until', '>', now())),
                            'suspended' => $query->whereHas('driver', fn (Builder $query): Builder => $query->whereNotNull('auth_suspended_at')),
                            default => $query,
                        };
                    }),
                Tables\Filters\TernaryFilter::make('is_ladies_driver')
                    ->label('Driver Ladies')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('driver', fn (Builder $query): Builder => $query->where('is_ladies_driver', true)),
                        false: fn (Builder $query): Builder => $query->whereHas('driver', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('is_ladies_driver', false)->orWhereNull('is_ladies_driver'))),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(2)
            ->headerActions([
                Tables\Actions\Action::make('exportDrivers')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (): bool => self::canManageDriverAuth())
                    ->action(fn () => app(DriverManagementCsvService::class)->downloadCsv()),
                Tables\Actions\Action::make('importDrivers')
                    ->label('Import CSV')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->visible(fn (): bool => self::canManageDriverAuth())
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('File CSV dari Export Driver')
                            ->disk('local')
                            ->directory('imports/driver-management')
                            ->visibility('private')
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/csv',
                                'application/vnd.ms-excel',
                                'application/octet-stream',
                                'text/comma-separated-values',
                            ])
                            ->maxSize(5120)
                            ->required()
                            ->helperText('Gunakan file CSV hasil export. Edit di Excel, lalu simpan lagi sebagai CSV. Pisahkan layanan dengan tanda |.'),
                    ])
                    ->modalHeading('Import Driver dari CSV')
                    ->modalDescription('Data akan update driver yang sudah ada berdasarkan user_id/driver_id. Sistem tidak membuat driver baru.')
                    ->action(function (array $data): void {
                        $path = (string) ($data['file'] ?? '');

                        if (blank($path) || ! Storage::disk('local')->exists($path)) {
                            Notification::make()
                                ->title('File import tidak ditemukan')
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = app(DriverManagementCsvService::class)->importCsv(Storage::disk('local')->path($path));
                        Storage::disk('local')->delete($path);

                        $body = "Berhasil update {$result['updated']} driver.";
                        if ($result['skipped'] > 0) {
                            $body .= " Gagal/skip {$result['skipped']} baris.";
                        }
                        if ($result['errors'] !== []) {
                            $body .= "\n".implode("\n", $result['errors']);
                        }

                        Notification::make()
                            ->title('Import driver selesai')
                            ->body($body)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (User $record): bool => self::canControlSuspend() && $record->driver !== null)
                    ->form([
                        Forms\Components\Select::make('duration')
                            ->label('Durasi')
                            ->options([
                                1 => '1 jam',
                                3 => '3 jam',
                                12 => '12 jam',
                                24 => '24 jam',
                                72 => '3x24 jam',
                                168 => '7x24 jam',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'suspended' => 'Suspended',
                                'suspended_unpaid' => 'Suspend belum bayar setoran',
                            ])
                            ->default('suspended')
                            ->required()
                            ->native(false),
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan')
                            ->required()
                            ->default('Suspend manual admin'),
                    ])
                    ->action(function (User $record, array $data): void {
                        app(DriverSuspendService::class)->suspend(
                            $record->driver,
                            (int) $data['duration'],
                            $data['reason'],
                            Auth::user(),
                            $data['status'],
                        );

                        Notification::make()->title('Driver disuspend')->success()->send();
                    }),
                    Tables\Actions\Action::make('release')
                    ->label('Release Suspend')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record): bool => self::canControlSuspend() && (bool) $record->driver?->isSuspended())
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        app(DriverSuspendService::class)->release($record->driver, Auth::user());

                        Notification::make()->title('Suspend driver dirilis')->success()->send();
                    }),
                    Tables\Actions\Action::make('resetToken')
                    ->label('Reset Token')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (User $record): bool => self::canControlSuspend() && $record->driver !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Semua token login driver ini akan dicabut. Driver harus login ulang di perangkat yang dipakai.')
                    ->action(function (User $record): void {
                        $record->tokens()->delete();

                        Notification::make()->title('Token driver direset')->body('Minta driver login ulang.')->success()->send();
                    }),
                    Tables\Actions\Action::make('googleAuthEmail')
                    ->label('Edit Google Email')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->visible(fn (User $record): bool => self::canManageDriverAuth() && $record->driver !== null)
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('Email Google Driver')
                            ->email()
                            ->required()
                            ->default(fn (User $record): string => self::driverGoogleEmail($record))
                            ->helperText('Email ini dipakai untuk mencocokkan akun Google driver.'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $driver = $record->driver;
                        if (! $driver) {
                            return;
                        }

                        $oldEmail = self::driverGoogleEmail($record);
                        $email = strtolower((string) $data['email']);

                        $record->forceFill(['email' => $email])->save();
                        if (Schema::hasColumn('drivers', 'email')) {
                            $driver->forceFill(['email' => $email])->save();
                        }

                        self::recordDriverAuthAudit('filament_updated_driver_google_email', $record, [
                            'driver_id' => $driver->id,
                            'old_email' => $oldEmail,
                            'new_email' => $email,
                        ]);

                        Notification::make()->title('Email Google driver tersimpan')->success()->send();
                    }),
                    Tables\Actions\Action::make('resetGoogleBind')
                    ->label('Reset Google Bind')
                    ->icon('heroicon-o-link-slash')
                    ->color('warning')
                    ->visible(fn (User $record): bool => self::canManageDriverAuth() && $record->driver !== null)
                    ->requiresConfirmation()
                    ->modalDescription('google_id akan dikosongkan, token aktif dicabut, dan driver dapat login ulang memakai akun Google baru.')
                    ->action(function (User $record): void {
                        $record->driver?->forceFill([
                            'google_id' => null,
                            'auth_failed_attempts' => 0,
                            'auth_locked_until' => null,
                        ])->save();
                        $record->tokens()->delete();

                        self::recordDriverAuthAudit('filament_reset_driver_google_bind', $record, ['driver_id' => $record->driver?->id]);

                        Notification::make()->title('Google bind driver direset')->body('Driver dapat login ulang dengan akun Google baru.')->success()->send();
                    }),
                    Tables\Actions\Action::make('suspendGoogleAuth')
                    ->label('Suspend Auth')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->visible(fn (User $record): bool => self::canManageDriverAuth() && $record->driver !== null && $record->driver->auth_suspended_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Auth Google driver akan disuspend dan semua token aktif dicabut.')
                    ->action(function (User $record): void {
                        $record->driver?->forceFill(['auth_suspended_at' => now()])->save();
                        $record->tokens()->delete();

                        self::recordDriverAuthAudit('filament_suspended_driver_google_auth', $record, ['driver_id' => $record->driver?->id]);

                        Notification::make()->title('Auth Google driver disuspend')->success()->send();
                    }),
                    Tables\Actions\Action::make('unlockGoogleAuth')
                    ->label('Unlock Auth')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (User $record): bool => self::canManageDriverAuth() && $record->driver !== null && (
                        $record->driver->auth_suspended_at !== null
                        || $record->driver->auth_locked_until !== null
                        || (int) $record->driver->auth_failed_attempts > 0
                    ))
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->driver?->forceFill([
                            'auth_suspended_at' => null,
                            'auth_locked_until' => null,
                            'auth_failed_attempts' => 0,
                        ])->save();

                        self::recordDriverAuthAudit('filament_unlocked_driver_google_auth', $record, ['driver_id' => $record->driver?->id]);

                        Notification::make()->title('Auth Google driver di-unlock')->success()->send();
                    }),
                    Tables\Actions\Action::make('config')
                    ->label('Config Layanan')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->visible(fn (User $record): bool => self::canControlSuspend() && $record->driver !== null)
                    ->form([
                        Forms\Components\Select::make('vehicle_type')
                            ->label('Tipe Kendaraan')
                            ->options([
                                'motor' => 'Driver sepeda motor',
                                'mobil' => 'Driver mobil',
                            ])
                            ->default(fn (User $record): string => $record->driver?->vehicle_type ?: 'motor')
                            ->required()
                            ->native(false),
                        Forms\Components\Select::make('vehicle_seat_rows')
                            ->label('Kapasitas Mobil')
                            ->options([
                                2 => '2 baris - citycar/default',
                                3 => '3 baris - MPV/lebih besar',
                            ])
                            ->default(fn (User $record): int => (int) ($record->driver?->vehicle_seat_rows ?: 2))
                            ->visible(fn (Forms\Get $get): bool => $get('vehicle_type') === 'mobil')
                            ->native(false),
                        Forms\Components\Toggle::make('is_ladies_driver')
                            ->label('Driver Ladies')
                            ->helperText('Jika aktif, driver bisa menerima order Ojek Ladies sesuai area/cabang.')
                            ->default(fn (User $record): bool => (bool) $record->driver?->is_ladies_driver),
                        Forms\Components\CheckboxList::make('allowed_service_types')
                            ->label('Layanan yang bisa diterima')
                            ->options(fn (): array => Service::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Service $service): array => [self::serviceTypeFromService($service) => $service->name])
                                ->all())
                            ->default(fn (User $record): array => $record->driver?->allowed_service_types ?? [])
                            ->helperText('Kosongkan semua jika driver boleh menerima semua layanan di area/cabangnya.')
                            ->columns(2),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->driver?->update([
                            'vehicle_type' => $data['vehicle_type'],
                            'vehicle_seat_rows' => $data['vehicle_type'] === 'mobil' ? ($data['vehicle_seat_rows'] ?? 2) : null,
                            'is_ladies_driver' => (bool) ($data['is_ladies_driver'] ?? false),
                            'allowed_service_types' => array_values($data['allowed_service_types'] ?? []),
                        ]);

                        Notification::make()->title('Config layanan driver tersimpan')->success()->send();
                    }),
                    Tables\Actions\Action::make('history')
                    ->label('Histori')
                    ->icon('heroicon-o-clock')
                    ->modalHeading(fn (User $record): string => 'Histori Suspend '.$record->name)
                    ->modalContent(fn (User $record) => view('filament.resources.driver-management.history', [
                        'suspensions' => $record->driver?->suspensions()->latest()->limit(20)->get() ?? collect(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),
                ])
                    ->label('Kelola')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button(),
            ])
            ->defaultSort('name')
            ->striped();
    }

    public static function canControlSuspend(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, [UserRole::Admin, UserRole::GM, UserRole::HRD, UserRole::Manager], true);
    }

    public static function canManageDriverAuth(): bool
    {
        return static::canControlSuspend()
            && Auth::user()?->hasPermission('suspend_driver') === true;
    }

    private static function googleAuthState(User $record): string
    {
        $driver = $record->driver;

        if (! $driver) {
            return 'No Driver';
        }

        if ($driver->auth_suspended_at !== null) {
            return 'Suspended';
        }

        if ($driver->auth_locked_until !== null && $driver->auth_locked_until->isFuture()) {
            return 'Locked';
        }

        return filled($driver->google_id) ? 'Bound' : 'Unbound';
    }

    private static function driverGoogleEmail(User $record): string
    {
        if (Schema::hasColumn('drivers', 'email') && filled($record->driver?->email)) {
            return (string) $record->driver->email;
        }

        return (string) ($record->email ?? '');
    }

    private static function recordDriverAuthAudit(string $action, User $driverUser, array $metadata = []): void
    {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $driverUser->id,
            'subject_label' => $driverUser->email,
            'metadata' => [
                ...$metadata,
                'driver_user_id' => $driverUser->id,
                'driver_email' => $driverUser->email,
            ],
        ]);
    }

    private static function serviceTypeFromService(Service $service): string
    {
        return match (strtoupper($service->code)) {
            'OJ' => 'ojek',
            'KR' => 'kurir',
            'DO' => 'delivery',
            'BL' => 'belanja',
            'GO' => 'gift_order',
            'JM' => 'joker_mobil',
            'TV' => 'travel',
            default => strtolower($service->code),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDriverManagement::route('/'),
        ];
    }
}
