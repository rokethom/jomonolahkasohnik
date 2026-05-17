<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\OrderParserService;
use App\Services\PricingService;
use App\Services\TextFormatter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Throwable;

class JojoBotTemplateParserLab extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'Template Parser Lab';

    protected static ?string $title = 'JojoBot Template Parser Lab';

    protected static ?string $slug = 'jojobot-template-parser-lab';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.jojobot-template-parser-lab';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'raw_text' => $this->sampleText(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Textarea::make('raw_text')
                    ->label('Paste format order')
                    ->rows(20)
                    ->live(onBlur: false)
                    ->helperText('Preview-only. Tidak menyimpan template dan tidak mengubah parser existing.'),
            ]);
    }

    public function getPreview(): array
    {
        $rawText = trim((string) data_get($this->data, 'raw_text', ''));
        $fields = $this->extractFields($rawText);
        $service = $this->detectServiceLabel($rawText);

        if ($rawText === '') {
            return [
                'matched' => false,
                'service' => '-',
                'message' => 'Paste format order untuk melihat preview parser.',
                'fields' => [],
                'parsed' => null,
                'payload' => null,
                'quote' => null,
                'price_rows' => [],
                'reply' => null,
                'error' => null,
            ];
        }

        $parsed = null;
        $payload = null;
        $quote = null;
        $reply = null;
        $error = null;

        try {
            $parsed = app(OrderParserService::class)->parse($this->previewUser(), $rawText);
            $payload = $parsed['payload'] ?? null;

            if (is_array($payload)) {
                $quote = app(PricingService::class)->calculate($payload);
                $reply = app(TextFormatter::class)->smartParserReply($parsed, $quote);
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return [
            'matched' => $parsed !== null,
            'service' => $parsed['service_type'] ?? $service,
            'message' => $parsed !== null
                ? 'Format dikenali oleh parser existing.'
                : 'Format belum sepenuhnya dikenali parser existing. Field mentah tetap ditampilkan untuk analisa.',
            'fields' => $fields,
            'parsed' => $parsed,
            'payload' => $payload,
            'quote' => $quote,
            'price_rows' => $this->pricePreviewRows($quote, $payload, $parsed),
            'reply' => $reply,
            'error' => $error,
        ];
    }

    private function sampleText(): string
    {
        return "Pesanan Kurir\n\nNama : \nHp / WhatsApp : \nAlamat : \n\nAntarkan barang ke\nNama : \nHP/WA : \nAlamat : \n\nJenis barang : \nHarga Barang: \n\nHarga Jasa: ";
    }

    private function previewUser(): User
    {
        return new User([
            'id' => 0,
            'name' => 'Preview Customer',
            'phone' => '0800000000',
            'address' => 'Alamat profile customer',
            'role' => 'customer',
            'branch_id' => null,
        ]);
    }

    private function extractFields(string $rawText): array
    {
        $rows = [];
        $section = 'Pembuka';

        foreach (preg_split('/\R/u', $rawText) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! str_contains($line, ':')) {
                $section = $line;
                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $line, 2));

            $rows[] = [
                'section' => $section,
                'label' => $label,
                'value' => $value,
                'target' => $this->targetFor($label, $section),
                'status' => $this->statusFor($label),
            ];
        }

        return $rows;
    }

    private function targetFor(string $label, string $section): string
    {
        $key = mb_strtolower($label);
        $sectionKey = mb_strtolower($section);
        $isReceiver = str_contains($sectionKey, 'antarkan')
            || str_contains($sectionKey, 'diantar')
            || str_contains($sectionKey, 'tujuan');

        if (preg_match('/nama/u', $key) === 1) {
            return $isReceiver ? 'receiver.name' : 'sender.name';
        }

        if (preg_match('/hp|wa|whatsapp|phone|telepon/u', $key) === 1) {
            return $isReceiver ? 'receiver.phone' : 'sender.phone';
        }

        if (preg_match('/pembelian|toko|store|warung|resto|pasar/u', $key) === 1) {
            return 'store_location';
        }

        if (preg_match('/alamat/u', $key) === 1) {
            return $isReceiver ? 'destination_address' : 'pickup_address / display_address';
        }

        if (preg_match('/jenis.*barang|barang|item|produk/u', $key) === 1) {
            return 'items[].name';
        }

        if (preg_match('/harga.*barang/u', $key) === 1) {
            return 'items[].price';
        }

        if (preg_match('/harga.*jasa|jasa/u', $key) === 1) {
            return 'manual_service_price';
        }

        if (preg_match('/harga/u', $key) === 1) {
            return 'price';
        }

        if (preg_match('/catatan|note/u', $key) === 1) {
            return 'notes';
        }

        return 'unmapped_preview';
    }

    private function statusFor(string $label): string
    {
        return $this->targetFor($label, '') === 'unmapped_preview' ? 'Review' : 'Known';
    }

    private function detectServiceLabel(string $rawText): string
    {
        return match (true) {
            preg_match('/kurir/iu', $rawText) === 1 => 'kurir',
            preg_match('/ojek/iu', $rawText) === 1 => 'ojek',
            preg_match('/gift|kado|hadiah/iu', $rawText) === 1 => 'gift_order',
            preg_match('/delivery|pesanan\s+do|\bdo\b/iu', $rawText) === 1 => 'DO',
            preg_match('/belanja|belikan/iu', $rawText) === 1 => 'belanja',
            default => '-',
        };
    }

    private function pricePreviewRows(?array $quote, ?array $payload, ?array $parsed): array
    {
        if (! is_array($quote)) {
            return [];
        }

        return array_values(array_filter([
            $this->row('Jarak', $this->formatDistance($quote['distance_km'] ?? $quote['distance'] ?? null)),
            $this->row('Sumber tarif', $this->tarifSourceLabel($quote)),
            $this->row('Cabang', $this->branchLabel($payload, $parsed)),
            $this->row('Tarif dasar', $this->money($quote['tarif'] ?? $quote['price'] ?? 0), 'strong'),
            $this->row('Service fee', $this->money($quote['service_fee'] ?? $quote['service_charge'] ?? 0)),
            $this->row('Tambahan keyword', $this->money($quote['keyword_charge'] ?? $quote['extra_charge'] ?? 0), (int) ($quote['keyword_charge'] ?? $quote['extra_charge'] ?? 0) > 0 ? 'warn' : null),
            $this->row('Tarif malam', $this->nightTariffLabel($quote), filled($quote['night_tariff_charge'] ?? null) ? 'warn' : null),
            $this->row('Helper / crew', $this->helperLabel($quote), filled($quote['crew_helper_fee'] ?? $quote['helper_service_charge'] ?? null) ? 'warn' : null),
            $this->row('Ring pricing', $this->ringPricingLabel($quote), filled($quote['ring_pricing_rule_id'] ?? null) ? 'good' : null),
            $this->row('Zone pricing', $this->zonePricingLabel($quote), filled($quote['zone_pricing_rule_id'] ?? null) ? 'good' : null),
            $this->row('Subtotal sebelum pembulatan', $this->money($quote['total_before_round'] ?? $quote['subtotal'] ?? 0)),
            $this->row('Total final', $this->money($quote['total_price'] ?? $quote['final_price'] ?? 0), 'strong'),
        ], fn (?array $row): bool => $row !== null));
    }

    private function row(string $label, ?string $value, ?string $tone = null): ?array
    {
        if (! filled($value)) {
            return null;
        }

        return [
            'label' => $label,
            'value' => $value,
            'tone' => $tone,
        ];
    }

    private function tarifSourceLabel(array $quote): string
    {
        if (filled($quote['zone_pricing_rule_id'] ?? null)) {
            return 'Zone Pricing Rule';
        }

        if (filled($quote['ring_pricing_rule_id'] ?? null)) {
            return 'Master Ring Pricing';
        }

        return match ((string) ($quote['tarif_source'] ?? 'distance_hardcoded')) {
            'price_settings' => 'Distance Price Settings CMS',
            'zone_pricing' => 'Zone Pricing Rule',
            default => 'Distance pricing service',
        };
    }

    private function branchLabel(?array $payload, ?array $parsed): ?string
    {
        $branch = data_get($parsed, 'branch');
        $display = data_get($branch, 'display_name')
            ?: trim(implode(' - ', array_filter([
                data_get($branch, 'name'),
                data_get($branch, 'area'),
            ])));

        if (filled($display)) {
            return (string) $display;
        }

        $branchId = data_get($payload, 'branch_id');

        return filled($branchId) ? 'Branch ID '.$branchId : null;
    }

    private function ringPricingLabel(array $quote): ?string
    {
        if (! filled($quote['ring_pricing_rule_id'] ?? null)) {
            return null;
        }

        $route = data_get($quote, 'ring_route');

        return trim(implode(' | ', array_filter([
            'Rule #'.$quote['ring_pricing_rule_id'],
            $quote['ring'] ?? null,
            filled($route) ? trim(implode(' -> ', array_filter([
                data_get($route, 'pickup_area'),
                data_get($route, 'destination_area'),
            ]))) : null,
            $quote['ring_pricing_source'] ?? null,
        ])));
    }

    private function zonePricingLabel(array $quote): ?string
    {
        if (! filled($quote['zone_pricing_rule_id'] ?? null)) {
            return null;
        }

        $adjustment = (int) ($quote['zone_pricing_adjustment'] ?? 0);

        return trim(implode(' | ', array_filter([
            $quote['zone_pricing_rule_name'] ?? 'Rule #'.$quote['zone_pricing_rule_id'],
            $quote['zone_pricing_area_name'] ?? null,
            $quote['zone_pricing_mode'] ?? null,
            $adjustment !== 0 ? 'Adjust '.$this->money($adjustment) : null,
        ])));
    }

    private function nightTariffLabel(array $quote): ?string
    {
        $amount = (int) ($quote['night_tariff_charge'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        return trim(implode(' | ', array_filter([
            $this->money($amount),
            filled($quote['night_tariff_percent'] ?? null) ? ((int) $quote['night_tariff_percent']).'%' : null,
        ])));
    }

    private function helperLabel(array $quote): ?string
    {
        $amount = (int) ($quote['crew_helper_fee'] ?? $quote['helper_service_charge'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        return trim(implode(' | ', array_filter([
            data_get($quote, 'crew_decision.helper_label', 'Helper'),
            data_get($quote, 'crew_decision.rule_name'),
            $this->money($amount),
        ])));
    }

    private function formatDistance(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').' KM';
    }

    private function money(mixed $value): string
    {
        return 'Rp '.number_format((int) $value, 0, ',', '.');
    }
}
