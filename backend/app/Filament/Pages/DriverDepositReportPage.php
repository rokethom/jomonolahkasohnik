<?php

namespace App\Filament\Pages;

use App\Services\DriverReportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriverDepositReportPage extends Page implements HasForms
{
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
            ->columns(2)
            ->statePath('data');
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
