<?php

namespace App\Filament\Resources\DriverDepositResource\Pages;

use App\Filament\Resources\DriverDepositResource;
use App\Models\DriverDeposit;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListDriverDeposits extends ListRecords
{
    protected static string $resource = DriverDepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCurrentView')
                ->label('Export current view')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadDeposits(
                    $this->getFilteredSortedTableQuery(),
                    $this->exportFilename('current-view'),
                )),
            Actions\Action::make('exportByDriver')
                ->label('Export by driver')
                ->icon('heroicon-o-funnel')
                ->color('info')
                ->form([
                    Forms\Components\Select::make('driver_id')
                        ->label('Visible driver')
                        ->options(DriverDepositResource::driverOptions())
                        ->native(false)
                        ->searchable()
                        ->required(),
                ])
                ->action(fn (array $data): StreamedResponse => $this->downloadDeposits(
                    DriverDepositResource::getEloquentQuery()->where('driver_id', (int) $data['driver_id']),
                    $this->exportFilename('driver-'.$data['driver_id']),
                )),
        ];
    }

    private function downloadDeposits(Builder $query, string $filename): StreamedResponse
    {
        $headers = [
            'Driver',
            'Bulan',
            'Tahun',
            'Handle Day 15',
            'Handle Day 30',
            'Bansos',
            'BPJS',
            'BPJS JHT',
            'Total',
            'Paid Amount',
            'Status',
            'Due Date',
            'Paid At',
        ];

        return response()->streamDownload(function () use ($query, $headers): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            $query
                ->with(['driver.user'])
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->chunk(200, function ($deposits) use ($handle): void {
                    foreach ($deposits as $deposit) {
                        /** @var DriverDeposit $deposit */
                        fputcsv($handle, [
                            $deposit->driver?->user?->username ?: ($deposit->driver?->user?->name ?? 'Driver #'.$deposit->driver_id),
                            $deposit->month,
                            $deposit->year,
                            $deposit->handle_day_15,
                            $deposit->handle_day_30,
                            $deposit->bansos,
                            $deposit->bpjs,
                            $deposit->bpjs_jht,
                            $deposit->total,
                            $deposit->paid_amount,
                            $deposit->status,
                            $deposit->due_date?->toDateString(),
                            $deposit->paid_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportFilename(string $scope): string
    {
        return 'driver-deposits-'.str($scope)->slug()->toString().'-'.now()->format('Ymd-His').'.csv';
    }
}
