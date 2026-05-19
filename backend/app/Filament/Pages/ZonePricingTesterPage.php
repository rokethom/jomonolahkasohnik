<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\ZonePricingRuleResource;
use App\Services\BranchDetectionService;
use App\Services\PricingService;
use App\Services\ZonePricingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class ZonePricingTesterPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Pricing Management';

    protected static ?string $navigationLabel = 'Zone Pricing Tester';

    protected static ?string $title = 'Zone Pricing Tester';

    protected static ?string $slug = 'zone-pricing-tester';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.zone-pricing-tester-page';

    public ?array $data = [];

    public ?array $result = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'service_type' => 'delivery',
            'stops' => 1,
            'pickup_lat' => -7.70630000,
            'pickup_lng' => 114.00980000,
            'destination_lat' => -7.71000000,
            'destination_lng' => 114.02000000,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Simulasi order')
                    ->description('Gunakan halaman ini untuk mengecek apakah titik pickup/tujuan masuk geofence yang benar dan rule Zone Pricing mana yang akan dipakai sebelum dicoba di FE customer.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('tester_flow')
                            ->label('Alur tester')
                            ->content('Isi koordinat pickup dan tujuan -> klik Test zone pricing -> sistem akan mendeteksi geofence tujuan lebih dulu, lalu pickup sebagai fallback -> hasil tarif menampilkan source dan rule zona jika ada.')
                            ->columnSpanFull(),
                        Forms\Components\Select::make('branch_id')
                            ->label('Cabang fallback')
                            ->options(fn (): array => ZonePricingRuleResource::branchOptions())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->helperText('Opsional. Jika titik tujuan/pickup masuk geofence, cabang akan otomatis mengikuti geofence.'),
                        Forms\Components\Select::make('service_type')
                            ->label('Layanan')
                            ->options(fn (): array => ZonePricingRuleResource::serviceOptions())
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\TextInput::make('pickup_lat')
                            ->label('Pickup latitude')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('pickup_lng')
                            ->label('Pickup longitude')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('destination_lat')
                            ->label('Tujuan latitude')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('destination_lng')
                            ->label('Tujuan longitude')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('stops')
                            ->label('Jumlah titik')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan/keyword')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function testPricing(
        BranchDetectionService $branches,
        PricingService $pricing,
        ZonePricingService $zones,
    ): void {
        $data = $this->form->getState();
        $pickup = $zones->testPoint((float) $data['pickup_lat'], (float) $data['pickup_lng']);
        $destination = $zones->testPoint((float) $data['destination_lat'], (float) $data['destination_lng']);
        $detectedBranchId = $branches->detect((float) $data['destination_lat'], (float) $data['destination_lng'])['branch']?->id
            ?? $branches->detect((float) $data['pickup_lat'], (float) $data['pickup_lng'])['branch']?->id
            ?? ($data['branch_id'] ? (int) $data['branch_id'] : null);

        $payload = [
            ...$data,
            'branch_id' => $detectedBranchId,
            'pickup_address' => 'Tester pickup',
            'destination_address' => 'Tester tujuan',
            'destination_text' => $data['notes'] ?? 'Tester tujuan',
        ];

        $quote = $pricing->calculate($payload);

        $this->result = [
            'pickup' => $pickup,
            'destination' => $destination,
            'branch_id' => $detectedBranchId,
            'quote' => $quote,
        ];
    }
}
