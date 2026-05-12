<?php

namespace App\Filament\Support;

use App\Services\PricingCmsCsvService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Facades\Storage;

class PricingCsvTableActions
{
    /**
     * @return array<int, Tables\Actions\Action|Tables\Actions\ActionGroup>
     */
    public static function make(string $type): array
    {
        return [
            Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('download_pricing_template_'.$type)
                    ->label('Download Template CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn () => app(PricingCmsCsvService::class)->downloadCsv($type, true)),
                Tables\Actions\Action::make('export_pricing_'.$type)
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => app(PricingCmsCsvService::class)->downloadCsv($type)),
                Tables\Actions\Action::make('import_pricing_'.$type)
                    ->label('Import CSV')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->form([
                        Forms\Components\FileUpload::make('file')
                            ->label('File CSV dari Excel')
                            ->disk('local')
                            ->directory('imports/pricing-cms')
                            ->acceptedFileTypes([
                                'text/csv',
                                'text/plain',
                                'application/csv',
                                'application/vnd.ms-excel',
                            ])
                            ->helperText('Gunakan file CSV delimiter titik koma (;). Download template dulu agar format kolom aman.')
                            ->required(),
                    ])
                    ->action(function (array $data) use ($type): void {
                        $storedPath = is_array($data['file'] ?? null)
                            ? (string) reset($data['file'])
                            : (string) ($data['file'] ?? '');

                        if ($storedPath === '') {
                            Notification::make()
                                ->title('Import gagal')
                                ->body('File CSV belum dipilih.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = app(PricingCmsCsvService::class)->importCsv(
                            $type,
                            Storage::disk('local')->path($storedPath),
                        );

                        $body = 'Created: '.$result['created'].' | Updated: '.$result['updated'].' | Skipped: '.$result['skipped'];
                        if ($result['errors'] !== []) {
                            $body .= "\n\n".implode("\n", $result['errors']);
                        }

                        $notification = Notification::make()
                            ->title($result['skipped'] > 0 ? 'Import selesai dengan catatan' : 'Import selesai')
                            ->body($body);

                        $result['skipped'] > 0
                            ? $notification->warning()
                            : $notification->success();

                        $notification->send();
                    }),
            ])
                ->label('Excel CSV')
                ->icon('heroicon-o-table-cells')
                ->button()
                ->color('gray'),
        ];
    }
}
