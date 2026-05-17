<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\LivePriceReview;
use App\Services\SettingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LivePriceReviewSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Live Edit Harga';

    protected static ?string $title = 'Live Edit Harga Customer';

    protected static ?string $slug = 'live-price-review-settings';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.live-price-review-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM], true);
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill([
            'live_price_review_enabled' => $settings->bool('live_price_review_enabled', false),
            'live_price_review_delay_seconds' => max(5, min(10, $settings->int('live_price_review_delay_seconds', 5))),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Kontrol Live Edit Harga Customer')
                    ->description('Jika aktif, preview harga customer akan menunggu operator/eksekutor/admin melakukan approve atau koreksi harga sebelum tombol konfirmasi order muncul.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('live_price_review_enabled')
                            ->label('Aktifkan live correction harga customer')
                            ->helperText('Customer melihat loading harga. Operator/eksekutor mengoreksi harga dari FE Admin > Operations > Live Edit Harga.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('live_price_review_delay_seconds')
                            ->label('Delay tombol konfirmasi customer')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(10)
                            ->suffix('detik')
                            ->helperText('Setelah harga di-approve, tombol konfirmasi customer muncul setelah jeda ini.'),
                        Forms\Components\Placeholder::make('flow')
                            ->label('Flow aktif')
                            ->content('Customer preview order -> harga loading -> operator/eksekutor edit/approve -> harga tampil ke customer -> customer konfirmasi order -> AI learning menyimpan koreksi.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->form->getState();

        $settings->set('live_price_review_enabled', (bool) ($data['live_price_review_enabled'] ?? false));
        $settings->set('live_price_review_delay_seconds', max(5, min(10, (int) ($data['live_price_review_delay_seconds'] ?? 5))));

        Notification::make()
            ->title('Live Edit Harga tersimpan')
            ->body('Pengaturan customer live correction sudah diperbarui.')
            ->success()
            ->send();

        $this->mount($settings);
    }

    /**
     * @return Collection<int, LivePriceReview>
     */
    public function getAuditRowsProperty(): Collection
    {
        return $this->auditQuery()
            ->limit(100)
            ->get();
    }

    public function downloadAuditXls(): StreamedResponse
    {
        $rows = $this->auditQuery()
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($rows): void {
            echo '<table border="1">';
            echo '<thead><tr>';
            foreach ($this->auditHeaders() as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($rows as $review) {
                echo '<tr>';
                foreach ($this->auditRow($review) as $cell) {
                    echo '<td>'.e((string) $cell).'</td>';
                }
                echo '</tr>';
            }

            echo '</tbody></table>';
        }, 'audit-live-edit-harga-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function auditHeaders(): array
    {
        return [
            'Tanggal Review',
            'Status',
            'Reviewer',
            'Customer',
            'Cabang',
            'Layanan',
            'Order Code',
            'Tarif Sistem',
            'Total Sistem',
            'Tarif Koreksi',
            'Total Koreksi',
            'Selisih',
            'Alasan',
        ];
    }

    /**
     * @return array<int, string|int>
     */
    public function auditRow(LivePriceReview $review): array
    {
        $systemTotal = (int) $review->system_total_price;
        $correctedTotal = (int) ($review->corrected_total_price ?? $review->system_total_price);

        return [
            $review->reviewed_at?->timezone(config('app.timezone'))->format('d M Y H:i') ?? $review->updated_at?->timezone(config('app.timezone'))->format('d M Y H:i') ?? '-',
            $review->status,
            $review->reviewer?->name ?? '-',
            $review->customer?->name ?? '-',
            $review->branch?->display_name ?? $review->branch?->name ?? '-',
            $review->service_type ?? '-',
            $review->order?->order_code ?? '-',
            (int) $review->system_price,
            $systemTotal,
            (int) ($review->corrected_price ?? $review->system_price),
            $correctedTotal,
            $correctedTotal - $systemTotal,
            $review->correction_reason ?? '-',
        ];
    }

    private function auditQuery()
    {
        return LivePriceReview::query()
            ->with(['customer', 'branch', 'reviewer', 'order'])
            ->latest('updated_at');
    }
}
