<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Driver;
use App\Models\DriverDeposit;
use App\Models\User;
use App\Services\DriverFinanceService;
use App\Services\BranchAccessSettingService;
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
        'vehicle_type' => 'motor',
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
            'vehicle_type' => 'motor',
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
                Forms\Components\Select::make('vehicle_type')
                    ->label('Jenis driver')
                    ->options([
                        'motor' => 'Driver Motor',
                        'mobil' => 'Driver Mobil',
                    ])
                    ->live()
                    ->required(),
            ])
            ->columns([
                'default' => 1,
                'md' => 3,
                'xl' => 3,
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
        return app(DriverReportService::class)->monthlyDepositRows($this->month(), $this->year(), Auth::user(), $this->vehicleType());
    }

    public function headers(): array
    {
        return app(DriverReportService::class)->monthlyDepositHeaders($this->period());
    }

    public function exportCsv(): StreamedResponse
    {
        return $this->download('driver-setoran-'.$this->vehicleType().'-'.$this->period()->format('Y-m').'.csv', ',');
    }

    public function exportExcel(): StreamedResponse
    {
        return $this->download('driver-setoran-'.$this->vehicleType().'-'.$this->period()->format('Y-m').'.xls', "\t");
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

    public function updateDepositCell(int $driverId, string $field, mixed $value): array
    {
        if (! in_array($field, [
            'orders_count',
            'base_service_omset',
            'base_service_deposit',
            'previous_bill',
            'previous_cashback_reward',
            'bill_before_bansos',
            'bansos',
            'total_bill',
            'paid_amount',
            'remaining_bill',
            'status',
            'next_cashback',
        ], true)) {
            Notification::make()
                ->title('Kolom tidak bisa diedit')
                ->danger()
                ->send();

            return $this->gridPayload();
        }

        $driver = Driver::query()
            ->with('user.branch')
            ->find($driverId);

        if (! $driver) {
            Notification::make()
                ->title('Driver tidak ditemukan')
                ->danger()
                ->send();

            return $this->gridPayload();
        }

        $actor = Auth::user();
        if (! $actor || ! $actor->hasPermission('view_report') || ! $this->canEditDriverDeposit($actor, $driver)) {
            Notification::make()
                ->title('Tidak punya akses edit setoran driver ini')
                ->danger()
                ->send();

            return $this->gridPayload();
        }

        try {
            $paymentPeriod = $this->period();
            $depositPeriod = $this->depositPeriodForPayment($paymentPeriod);
            $finance = app(DriverFinanceService::class);
            $deposit = $finance->monthlyDeposit($driver, $depositPeriod->copy());
            $breakdown = $deposit->breakdown ?? [];
            $breakdown['manual_override'] = true;

            if ($field === 'base_service_deposit') {
                $deposit->handle_day_15 = $this->moneyToInt($value);
                $deposit->handle_day_30 = 0;
            } elseif ($field === 'orders_count') {
                $breakdown['manual_orders_count'] = $this->moneyToInt($value);
            } elseif ($field === 'base_service_omset') {
                $breakdown['manual_base_service_omset'] = $this->moneyToInt($value);
            } elseif ($field === 'previous_bill') {
                $breakdown['manual_previous_bill'] = $this->moneyToInt($value);
            } elseif ($field === 'previous_cashback_reward') {
                $breakdown['manual_previous_cashback_reward'] = $this->moneyToInt($value);
            } elseif ($field === 'bill_before_bansos') {
                $breakdown['manual_bill_before_bansos'] = $this->moneyToInt($value);
            } elseif ($field === 'total_bill') {
                $breakdown['manual_total_bill'] = $this->moneyToInt($value);
            } elseif ($field === 'remaining_bill') {
                $breakdown['manual_remaining_bill'] = $this->moneyToInt($value);
            } elseif ($field === 'next_cashback') {
                $breakdown['manual_next_cashback'] = $this->moneyToInt($value);
            } elseif (in_array($field, ['bansos', 'paid_amount'], true)) {
                $deposit->{$field} = $this->moneyToInt($value);
            } elseif ($field === 'status') {
                $status = strtolower(trim((string) $value));
                if (! in_array($status, ['paid', 'unpaid'], true)) {
                    throw new \InvalidArgumentException('Status hanya boleh paid atau unpaid.');
                }
                $deposit->status = $status;
            }

            if ($field === 'paid_amount') {
                $deposit->paid_at = (int) $deposit->paid_amount > 0 ? now() : null;
            }

            $base = (int) $deposit->handle_day_15 + (int) $deposit->handle_day_30;
            $deposit->bpjs = $finance->bpjsPremiumForBaseDeposit($base);
            $deposit->bpjs_jht = $finance->bpjsJhtForDriver($driver);

            $previous = $depositPeriod->copy()->subMonth();
            $previousDeposit = DriverDeposit::query()
                ->where('driver_id', $driver->id)
                ->where('year', $previous->year)
                ->where('month', $previous->month)
                ->first();

            $previousRemaining = array_key_exists('manual_previous_bill', $breakdown)
                ? max(0, (int) $breakdown['manual_previous_bill'])
                : max(0, (int) ($previousDeposit?->total ?? 0) - (int) ($previousDeposit?->paid_amount ?? 0));
            $previousBase = (int) ($previousDeposit?->handle_day_15 ?? 0) + (int) ($previousDeposit?->handle_day_30 ?? 0);
            $cashback = array_key_exists('manual_previous_cashback_reward', $breakdown)
                ? max(0, (int) $breakdown['manual_previous_cashback_reward'])
                : $this->cashbackForPreviousDeposit($previousDeposit, $previousBase);
            $billBeforeBansos = array_key_exists('manual_bill_before_bansos', $breakdown)
                ? max(0, (int) $breakdown['manual_bill_before_bansos'])
                : max(0, $base + $previousRemaining - $cashback + (int) $deposit->bpjs + (int) $deposit->bpjs_jht);

            $deposit->total = array_key_exists('manual_total_bill', $breakdown)
                ? max(0, (int) $breakdown['manual_total_bill'])
                : max(0, $billBeforeBansos + (int) $deposit->bansos);

            if ($field !== 'status') {
                $deposit->status = (int) $deposit->paid_amount >= (int) $deposit->total ? 'paid' : 'unpaid';
            }

            if ((int) $deposit->paid_amount > 0 && ! $deposit->paid_at) {
                $deposit->paid_at = now();
            }

            $breakdown['handle_hari_15'] = (int) $deposit->handle_day_15;
            $breakdown['handle_hari_30'] = (int) $deposit->handle_day_30;
            $breakdown['setoran_hingga_hari_ini'] = $base;
            $breakdown['tagihan_bulan_sebelumnya'] = $previousRemaining;
            $breakdown['cashback_bulan_sebelumnya'] = $cashback;
            $breakdown['bill_before_bansos'] = $billBeforeBansos;
            $breakdown['cashback_bulan_depan'] = $this->cashbackForPreviousDeposit($deposit, $base);
            $breakdown['bansos'] = (int) $deposit->bansos;
            $breakdown['bpjs'] = (int) $deposit->bpjs;
            $breakdown['bpjs_jht'] = (int) $deposit->bpjs_jht;
            $deposit->breakdown = $breakdown;
            $deposit->save();

            Notification::make()
                ->title('Setoran driver diperbarui')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Gagal menyimpan setoran')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        return $this->gridPayload();
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
            'vehicle_type' => $this->vehicleType(),
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
        $depositPeriod = $this->depositPeriodForPayment($period);
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

        DB::transaction(function () use ($handle, $separator, $headerMap, $depositPeriod, &$updated, &$skipped, &$errors, &$rowNumber): void {
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

                $deposit = app(DriverFinanceService::class)->monthlyDeposit($driver->load('user.branch'), $depositPeriod->copy());
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

    private function gridPayload(): array
    {
        return [
            'rows' => $this->rows()->values()->all(),
        ];
    }

    private function canEditDriverDeposit(User $actor, Driver $driver): bool
    {
        if (app(BranchAccessSettingService::class)->roleHasGlobalBranchAccess($actor->role)) {
            return true;
        }

        $branchIds = $this->staffBranchScopeIds($actor);

        return $driver->user?->branch_id !== null
            && in_array((int) $driver->user->branch_id, $branchIds, true);
    }

    /**
     * @return array<int, int>
     */
    private function staffBranchScopeIds(User $actor): array
    {
        $actor->loadMissing('branchScopes:id');

        $branchIds = $actor->branchScopes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($branchIds === [] && $actor->branch_id !== null) {
            $branchIds[] = (int) $actor->branch_id;
        }

        return Branch::expandToOperationalAreaIds($branchIds);
    }

    private function cashbackForPreviousDeposit(?DriverDeposit $previousDeposit, int $previousBaseDeposit): int
    {
        $manualNextCashback = data_get($previousDeposit?->breakdown, 'manual_next_cashback');
        if ($manualNextCashback !== null) {
            return max(0, (int) $manualNextCashback);
        }

        if (! $previousDeposit || $previousBaseDeposit <= 0) {
            return 0;
        }

        if ($previousDeposit->status !== 'paid' || ! $previousDeposit->paid_at) {
            return 0;
        }

        if ((int) $previousDeposit->paid_at->day >= 7) {
            return 0;
        }

        return (int) floor($previousBaseDeposit * 0.1);
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

    public function vehicleType(): string
    {
        $vehicleType = strtolower(trim((string) ($this->data['vehicle_type'] ?? 'motor')));

        return in_array($vehicleType, ['motor', 'mobil'], true) ? $vehicleType : 'motor';
    }

    private function period(): Carbon
    {
        return Carbon::create($this->year(), $this->month(), 1)->startOfMonth();
    }

    private function depositPeriodForPayment(Carbon $paymentPeriod): Carbon
    {
        return $paymentPeriod->copy()->startOfMonth();
    }
}
