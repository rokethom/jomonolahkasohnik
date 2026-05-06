<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\DriverManagementResource\Pages;
use App\Models\User;
use App\Models\Service;
use App\Services\DriverSuspendService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->extraAttributes(['class' => 'sticky left-0 bg-white dark:bg-gray-900 z-10'], merge: true)
                    ->extraHeaderAttributes(['class' => 'sticky left-0 bg-white dark:bg-gray-900 z-20'], merge: true)
                    ->extraCellAttributes(['class' => 'sticky left-0 bg-white dark:bg-gray-900 z-10'], merge: true),
                Tables\Columns\TextColumn::make('username')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->placeholder('-'),
                Tables\Columns\TextColumn::make('branch.name')->label('Branch')->placeholder('Global'),
                Tables\Columns\TextColumn::make('driver.vehicle_type')
                    ->label('Kendaraan')
                    ->badge()
                    ->default('motor'),
                Tables\Columns\TextColumn::make('driver.allowed_service_types')
                    ->label('Layanan')
                    ->formatStateUsing(fn (?array $state): string => $state ? implode(', ', $state) : 'Semua layanan')
                    ->badge(),
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
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('driver.oper_handle_count')
                    ->label('Oper')
                    ->numeric()
                    ->sortable()
                    ->extraAttributes(['class' => 'sticky right-0 bg-white dark:bg-gray-900 z-10'], merge: true)
                    ->extraHeaderAttributes(['class' => 'sticky right-0 bg-white dark:bg-gray-900 z-20'], merge: true)
                    ->extraCellAttributes(['class' => 'sticky right-0 bg-white dark:bg-gray-900 z-10'], merge: true),
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
            ])
            ->actions([
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
            ]);
    }

    public static function canControlSuspend(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, [UserRole::Admin, UserRole::GM, UserRole::HRD], true);
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
