<?php

namespace App\Filament\Pages;

use App\Models\Driver;
use App\Services\DriverFinanceService;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use App\Services\DriverReportService;
use Filament\Notifications\Notification;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverDepositReportPage extends Page implements HasForms, HasActions
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Report Setoran Driver';

    protected static ?string $slug = 'driver-deposit-report';

    protected static string $view = 'filament.pages.driver-deposit-report-page';

    public ?array $data = [
        'month' => null,
        'year' => null,
    ];

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->hasPermission('view_report') === true;
    }

    public function mount(): void
    {
        $this->form->fill([
            'month' => now()->month,
            'year' => now()->year,
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('month')
                    ->label('Bulan')
                    ->options(fn (): array => collect(range(1, 12))
                        ->mapWithKeys(fn (int $month): array => [$month => Carbon::create(2026, $month, 1)->translatedFormat('F')])
                        ->all())
                    ->live()
                    ->required(),
                Forms\Components\TextInput::make('year')
                    ->label('Tahun')
                    ->numeric()
                    ->minValue(2020)
                    ->maxValue(2100)
                    ->live()
                    ->required(),
            ])
            ->columns([
                'default' => 1,
                'md' => 2,
                'xl' => 2,
            ])
            ->statePath('data');
    }

    public function importDepositAction(): Actions\Action
    {
        return Actions\Action::make('importDeposit')
            ->label('Import Update Setoran')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->modalHeading('Import Update Setoran Driver')
            ->modalSubmitActionLabel('Import')
            ->form([
                Forms\Components\FileUpload::make('import_file')
                    ->label('File CSV / XLS')
                    ->disk('local')
                    ->directory('imports/driver-deposits')
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                    ])
                    ->helperText('Gunakan template CSV/XLS dari tombol Download Template. Sistem membaca DRIVER/email/username, Terbayar, Tgl Bayar, dan Status.')
                    ->preserveFilenames()
                    ->required()
                    ->maxSize(4096),
            ])
            ->action(function (array $data): void {
                $this->runDepositImport($data['import_file'] ?? null);
            });
    }

    public function rows(): Collection
    {
        return app(DriverReportService::class)->monthlyDepositRows($this->month(), $this->year());
    }

    public function headers(): array
    {
        return app(DriverReportService::class)->monthlyDepositHeaders($this->period());
    }

    public function exportCsv(): StreamedResponse
    {
        return $this->download('driver-setoran-'.$this->period()->format('Y-m').'.csv', ',');
    }

    public function exportExcel(): StreamedResponse
    {
        return $this->download('driver-setoran-'.$this->period()->format('Y-m').'.xls', "\t");
    }

    public function downloadTemplateCsv(): StreamedResponse
    {
        return $this->downloadTemplate('template-update-setoran-driver.csv', ',');
    }

    public function downloadTemplateExcel(): StreamedResponse
    {
        return $this->downloadTemplate('template-update-setoran-driver.xls', "\t");
    }

    public function importDepositFile(): void
    {
        $state = $this->form->getState();
        $this->runDepositImport($state['import_file'] ?? null);
    }

    private function runDepositImport(mixed $file): void
    {
        $upload = $this->uploadedImportFile($file);

        if (! $upload['path'] || ! is_file($upload['path'])) {
            Notification::make()
                ->title('File import belum dipilih')
                ->body('Pilih file CSV atau XLS dari tombol Import Update Setoran.')
                ->warning()
                ->send();

            return;
        }

        $period = $this->period();
        $result = $this->importRowsFromFile($upload['path'], $period);

        if ($upload['stored_path']) {
            Storage::disk('local')->delete($upload['stored_path']);
        }
        $this->form->fill([
            'month' => $this->month(),
            'year' => $this->year(),
        ]);

        $body = "{$result['updated']} driver diperbarui.";
        if ($result['skipped'] > 0) {
            $body .= " {$result['skipped']} baris dilewati.";
        }
        if ($result['errors'] !== []) {
            $body .= ' Catatan: '.implode(' ', array_slice($result['errors'], 0, 3));
        }

        Notification::make()
            ->title($result['updated'] > 0 ? 'Import setoran selesai' : 'Import tidak mengubah data')
            ->body($body)
            ->{$result['updated'] > 0 ? 'success' : 'warning'}()
            ->send();
    }

    private function download(string $filename, string $separator): StreamedResponse
    {
        $rows = $this->rows();
        $headers = $this->headers();

        return response()->streamDownload(function () use ($rows, $headers, $separator): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, $separator);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['driver'],
                    $row['area'],
                    $row['orders_count'],
                    $row['base_service_omset'],
                    $row['base_service_deposit'],
                    $row['previous_bill'],
                    $row['bpjs_jht'],
                    $row['bpjs'],
                    $row['previous_cashback_reward'],
                    $row['bill_before_bansos'],
                    $row['bansos'],
                    $row['total_bill'],
                    $row['paid_amount'],
                    $row['remaining_bill'],
                    $row['paid_at'],
                    $row['next_cashback'],
                ], $separator);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => $separator === ',' ? 'text/csv' : 'application/vnd.ms-excel',
        ]);
    }

    private function downloadTemplate(string $filename, string $separator): StreamedResponse
    {
        $headers = ['DRIVER', 'email', 'username', 'Terbayar', 'Tgl Bayar', 'Status'];

        return response()->streamDownload(function () use ($headers, $separator): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers, $separator);
            fputcsv($out, ['Nama Driver', 'driver@email.com', 'username_driver', '0', now()->toDateString(), 'unpaid'], $separator);
            fclose($out);
        }, $filename, [
            'Content-Type' => $separator === ',' ? 'text/csv' : 'application/vnd.ms-excel',
        ]);
    }

    private function importRowsFromFile(string $path, Carbon $period): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['updated' => 0, 'skipped' => 0, 'errors' => ['File tidak dapat dibaca.']];
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return ['updated' => 0, 'skipped' => 0, 'errors' => ['File kosong.']];
        }

        $separator = $this->detectSeparator($firstLine);
        rewind($handle);

        $headers = fgetcsv($handle, 0, $separator);
        if (! is_array($headers)) {
            fclose($handle);

            return ['updated' => 0, 'skipped' => 0, 'errors' => ['Header file tidak valid.']];
        }

        $headerMap = $this->headerMap($headers);
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;

        DB::transaction(function () use ($handle, $separator, $headerMap, $period, &$updated, &$skipped, &$errors, &$rowNumber): void {
            while (($values = fgetcsv($handle, 0, $separator)) !== false) {
                $rowNumber++;
                $row = $this->mapImportRow($headerMap, $values);
                $driverName = trim((string) ($row['driver'] ?? ''));

                if ($driverName === '') {
                    $skipped++;
                    continue;
                }

                $driver = $this->findDriver($row);
                if (! $driver) {
                    $skipped++;
                    $errors[] = "Baris {$rowNumber}: driver {$driverName} tidak ditemukan.";
                    continue;
                }

                $paidAmount = $this->moneyToInt($row['paid_amount'] ?? $row['terbayar'] ?? 0);
                $paidAt = $this->parsePaidAt($row['paid_at'] ?? $row['tgl_bayar'] ?? null, $paidAmount);

                $deposit = app(DriverFinanceService::class)->monthlyDeposit($driver->load('user.branch'), $period->copy());
                $status = $this->statusFromImport($row['status'] ?? null, $paidAmount, (int) $deposit->total);

                $deposit->forceFill([
                    'paid_amount' => $paidAmount,
                    'paid_at' => $paidAmount > 0 ? $paidAt : null,
                    'status' => $status,
                ])->save();

                $updated++;
            }
        });

        fclose($handle);

        return compact('updated', 'skipped', 'errors');
    }

    private function uploadedImportFile(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'getRealPath')) {
            return ['path' => $value->getRealPath(), 'stored_path' => null];
        }

        if (is_string($value)) {
            return [
                'path' => Storage::disk('local')->exists($value) ? Storage::disk('local')->path($value) : null,
                'stored_path' => $value,
            ];
        }

        if (is_array($value)) {
            $first = reset($value);

            return $this->uploadedImportFile($first);
        }

        return ['path' => null, 'stored_path' => null];
    }

    private function detectSeparator(string $line): string
    {
        $candidates = [
            "\t" => substr_count($line, "\t"),
            ',' => substr_count($line, ','),
            ';' => substr_count($line, ';'),
        ];

        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $key = Str::of((string) $header)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/i', '_')
                ->trim('_')
                ->toString();

            $map[$index] = match (true) {
                $key === 'driver' => 'driver',
                in_array($key, ['email', 'driver_email'], true) => 'email',
                in_array($key, ['username', 'driver_username'], true) => 'username',
                in_array($key, ['terbayar', 'paid_amount', 'paid'], true) => 'paid_amount',
                in_array($key, ['tgl_bayar', 'tanggal_bayar', 'paid_at', 'payment_date'], true) => 'paid_at',
                $key === 'status' => 'status',
                default => $key,
            };
        }

        return $map;
    }

    private function mapImportRow(array $headerMap, array $values): array
    {
        $row = [];

        foreach ($values as $index => $value) {
            if (! isset($headerMap[$index])) {
                continue;
            }

            $row[$headerMap[$index]] = is_string($value) ? trim($value) : $value;
        }

        return $row;
    }

    private function findDriver(array $row): ?Driver
    {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $username = strtolower(trim((string) ($row['username'] ?? '')));
        $name = strtolower(trim((string) ($row['driver'] ?? '')));

        return Driver::query()
            ->with('user.branch')
            ->whereHas('user', function ($query) use ($email, $username, $name): void {
                $query
                    ->when($email !== '', fn ($query) => $query->orWhereRaw('LOWER(email) = ?', [$email]))
                    ->when($username !== '', fn ($query) => $query->orWhereRaw('LOWER(username) = ?', [$username]))
                    ->when($name !== '', fn ($query) => $query->orWhereRaw('LOWER(name) = ?', [$name]));
            })
            ->first();
    }

    private function moneyToInt(mixed $value): int
    {
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return 0;
        }

        $numeric = preg_replace('/[^\d\-]/', '', $raw);

        return max(0, (int) $numeric);
    }

    private function parsePaidAt(mixed $value, int $paidAmount): ?Carbon
    {
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return $paidAmount > 0 ? now() : null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return $paidAmount > 0 ? now() : null;
        }
    }

    private function statusFromImport(mixed $value, int $paidAmount, int $total): string
    {
        $status = strtolower(trim((string) $value));

        if ($paidAmount <= 0) {
            return 'unpaid';
        }

        if ($status === 'paid' && ($total <= 0 || $paidAmount >= $total)) {
            return 'paid';
        }

        return $total > 0 && $paidAmount >= $total ? 'paid' : 'unpaid';
    }

    private function month(): int
    {
        return max(1, min(12, (int) ($this->data['month'] ?? now()->month)));
    }

    private function year(): int
    {
        return max(2020, min(2100, (int) ($this->data['year'] ?? now()->year)));
    }

    private function period(): Carbon
    {
        return Carbon::create($this->year(), $this->month(), 1)->startOfMonth();
    }
}
