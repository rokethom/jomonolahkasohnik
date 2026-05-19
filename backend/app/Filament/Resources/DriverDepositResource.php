<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Models\DriverDeposit;
use App\Models\User;
use App\Services\DriverFinanceService;
use App\Services\DriverSuspendService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class DriverDepositResource extends Resource
{
    protected static ?string $model = DriverDeposit::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Setoran Driver';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('driver_id')->numeric()->required(),
            Forms\Components\TextInput::make('year')->numeric()->required(),
            Forms\Components\TextInput::make('month')->numeric()->required(),
            Forms\Components\TextInput::make('paid_amount')->numeric()->required(),
            Forms\Components\Select::make('status')->options([
                'unpaid' => 'Unpaid',
                'paid' => 'Paid',
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.user.name')->label('Driver')->searchable(),
                Tables\Columns\TextColumn::make('month')->label('Bulan'),
                Tables\Columns\TextColumn::make('year')->label('Tahun'),
                Tables\Columns\TextColumn::make('total')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('paid_amount')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('due_date')->date(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('driver_id')
                    ->label('Visible driver')
                    ->placeholder('All visible drivers')
                    ->native(false)
                    ->searchable()
                    ->options(fn (): array => self::driverOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->placeholder('All statuses')
                    ->native(false)
                    ->options([
                        'unpaid' => 'Unpaid',
                        'paid' => 'Paid',
                    ]),
                Tables\Filters\SelectFilter::make('month')
                    ->label('Bulan')
                    ->placeholder('All months')
                    ->native(false)
                    ->options([
                        1 => 'Januari',
                        2 => 'Februari',
                        3 => 'Maret',
                        4 => 'April',
                        5 => 'Mei',
                        6 => 'Juni',
                        7 => 'Juli',
                        8 => 'Agustus',
                        9 => 'September',
                        10 => 'Oktober',
                        11 => 'November',
                        12 => 'Desember',
                    ]),
                Tables\Filters\SelectFilter::make('year')
                    ->label('Tahun')
                    ->placeholder('All years')
                    ->native(false)
                    ->options(fn (): array => DriverDeposit::query()
                        ->select('year')
                        ->distinct()
                        ->orderByDesc('year')
                        ->pluck('year', 'year')
                        ->all()),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->contentFooter(view('filament.resources.driver-deposit.flow'))
            ->actions([
                Tables\Actions\Action::make('markPaid')
                    ->label('Bayar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DriverDeposit $record): bool => max(0, (int) $record->total - (int) $record->paid_amount) > 0)
                    ->form(fn (DriverDeposit $record): array => [
                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal dibayar')
                            ->helperText('Tagihan: Rp '.number_format(max(0, (int) $record->total - (int) $record->paid_amount), 0, ',', '.'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(max(1, (int) $record->total - (int) $record->paid_amount))
                            ->required(),
                    ])
                    ->action(function (DriverDeposit $record, array $data): void {
                        $record = app(DriverFinanceService::class)->monthlyDeposit($record->driver, self::recordPeriod($record));
                        $targetTotal = (int) $record->total;
                        $paidAmount = min($targetTotal, (int) $record->paid_amount + (int) $data['amount']);
                        $isPaid = $targetTotal <= 0 || $paidAmount >= $targetTotal;

                        $record->forceFill([
                            'paid_amount' => $paidAmount,
                            'paid_at' => $paidAmount > 0 ? now() : null,
                            'status' => ($isPaid || $paidAmount > 0) ? 'paid' : 'unpaid',
                        ])->save();

                        if ($isPaid && $record->driver) {
                            app(DriverSuspendService::class)->releaseDepositSuspension($record->driver->load('user'), auth()->user());
                        }

                        $record->driver?->update(['is_available' => false]);

                        $notification = Notification::make()
                            ->title($isPaid ? 'Setoran driver lunas' : 'Pembayaran setoran tersimpan')
                            ->body($isPaid ? 'Driver bisa ON kembali dari aplikasi driver.' : 'Sisa tagihan tetap tercatat dan akan dicek pada tanggal 11.');

                        ($isPaid ? $notification->success() : $notification->warning())->send();
                    }),
                Tables\Actions\Action::make('markFullPaid')
                    ->label('Lunas')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (DriverDeposit $record): bool => max(0, (int) $record->total - (int) $record->paid_amount) > 0)
                    ->action(function (DriverDeposit $record): void {
                        $record = app(DriverFinanceService::class)->monthlyDeposit($record->driver, self::recordPeriod($record));
                        $record->forceFill([
                            'paid_amount' => (int) $record->total,
                            'paid_at' => now(),
                            'status' => 'paid',
                        ])->save();

                        if ($record->driver) {
                            app(DriverSuspendService::class)->releaseDepositSuspension($record->driver->load('user'), auth()->user());
                        }

                        $record->driver?->update(['is_available' => false]);

                        Notification::make()
                            ->title('Setoran driver lunas')
                            ->body('Tagihan bulan berikutnya tidak membawa sisa bulan ini.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('markUnpaid')
                    ->label('Unpaid')
                    ->icon('heroicon-o-x-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (DriverDeposit $record): bool => $record->status !== 'unpaid')
                    ->action(function (DriverDeposit $record): void {
                        $record = app(DriverFinanceService::class)->monthlyDeposit($record->driver, self::recordPeriod($record));
                        $record->forceFill([
                            'paid_amount' => 0,
                            'paid_at' => null,
                            'status' => 'unpaid',
                        ])->save();
                        $record->driver?->update(['is_available' => false]);

                        Notification::make()
                            ->title('Setoran driver unpaid')
                            ->body('Driver otomatis OFF dan tidak bisa menerima/request order.')
                            ->warning()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['driver.user.branch']);
    }

    public static function getPages(): array
    {
        return [
            'index' => DriverDepositResource\Pages\ListDriverDeposits::route('/'),
        ];
    }

    public static function driverOptions(): array
    {
        return User::query()
            ->where('role', UserRole::Driver->value)
            ->whereHas('driver')
            ->with('driver')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [$user->driver->id => $user->name])
            ->all();
    }

    private static function recordPeriod(DriverDeposit $record): Carbon
    {
        return Carbon::create((int) $record->year, (int) $record->month, 1)->startOfMonth();
    }
}
