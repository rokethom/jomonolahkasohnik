<?php

namespace App\Filament\Pages;

use App\Services\Pricing\JokerPricing;
use App\Services\SettingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class JokerMobilPricingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Pricing Management';

    protected static ?string $navigationLabel = 'Joker Mobil Pricing';

    protected static ?string $slug = 'joker-mobil-pricing';

    protected static string $view = 'filament.pages.joker-mobil-pricing-settings-page';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user?->hasPermission('manage_system_settings') === true
            || $user?->hasPermission('manage_cms') === true
            || $user?->hasPermission('edit_tarif') === true;
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(JokerPricing $pricing): void
    {
        $config = $pricing->config();

        $this->form->fill([
            'joker_mobil_ring1_max_km' => $config['ring1_max_km'],
            'joker_mobil_ring1_price' => $config['ring1_price'],
            'joker_mobil_ring2_max_km' => $config['ring2_max_km'],
            'joker_mobil_ring2_per_km' => $config['ring2_per_km'],
            'joker_mobil_ring2_add' => $config['ring2_add'],
            'joker_mobil_ring3_per_km' => $config['ring3_per_km'],
            'joker_mobil_ring3_add' => $config['ring3_add'],
            'joker_mobil_pickup_markup_percent' => $config['pickup_markup_percent'],
            'joker_mobil_deposit_ring1_max_jasa' => $config['deposit_ring1_max_jasa'],
            'joker_mobil_deposit_ring1_amount' => $config['deposit_ring1_amount'],
            'joker_mobil_deposit_ring2_percent' => $config['deposit_ring2_percent'],
            'joker_mobil_round_trip_second_point_percent' => $config['round_trip_second_point_percent'],
            'joker_mobil_round_trip_free_wait_minutes' => $config['round_trip_free_wait_minutes'],
            'joker_mobil_wait_price_per_block' => $config['wait_price_per_block'],
            'joker_mobil_wait_block_minutes' => $config['wait_block_minutes'],
            'joker_mobil_night_percent' => $config['night_percent'],
            'joker_mobil_helper_fee_increment' => $config['helper_fee_increment'],
            'joker_mobil_origin_points' => $pricing->originPoints(),
        ]);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Rumus tarif Joker Mobil')
                    ->description('Semua angka di sini langsung dipakai pricing Joker Mobil. Jarak dari maps dibulatkan: koma 5 turun, koma 6 naik.')
                    ->schema([
                        Forms\Components\TextInput::make('joker_mobil_ring1_max_km')
                            ->label('Ring 1 max KM maps')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_ring1_price')
                            ->label('Ring 1 jasa')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_ring2_max_km')
                            ->label('Ring 2 max KM maps')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_ring2_per_km')
                            ->label('Ring 2 per KM')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_ring2_add')
                            ->label('Ring 2 tambahan')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_ring3_per_km')
                            ->label('Ring 3 per KM')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_ring3_add')
                            ->label('Ring 3 tambahan')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_pickup_markup_percent')
                            ->label('Tarif Joker pickup')
                            ->numeric()
                            ->suffix('% dari Ring 1')
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4]),

                Forms\Components\Section::make('Setoran manajemen')
                    ->schema([
                        Forms\Components\TextInput::make('joker_mobil_deposit_ring1_max_jasa')
                            ->label('Ring 1 jasa max')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_deposit_ring1_amount')
                            ->label('Potongan Ring 1')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_deposit_ring2_percent')
                            ->label('Potongan jasa di atas Ring 1')
                            ->numeric()
                            ->suffix('%')
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'md' => 3]),

                Forms\Components\Section::make('Jasa PP, tunggu, malam, helper')
                    ->schema([
                        Forms\Components\TextInput::make('joker_mobil_round_trip_second_point_percent')
                            ->label('PP titik 2')
                            ->numeric()
                            ->suffix('% jasa')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_round_trip_free_wait_minutes')
                            ->label('Free tunggu PP')
                            ->numeric()
                            ->suffix('menit')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_wait_price_per_block')
                            ->label('Jasa tunggu')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_wait_block_minutes')
                            ->label('Blok tunggu')
                            ->numeric()
                            ->suffix('menit')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_night_percent')
                            ->label('Jasa malam')
                            ->numeric()
                            ->suffix('%')
                            ->required(),
                        Forms\Components\TextInput::make('joker_mobil_helper_fee_increment')
                            ->label('Kelipatan jasa angkut/helper')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                    ])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3]),

                Forms\Components\Section::make('Titik hitung jasa')
                    ->description('Isi patokan dan lat/lng. Sistem memilih titik CMS terdekat dari pickup, lalu menghitung jarak dari titik itu ke tujuan.')
                    ->schema([
                        Forms\Components\Repeater::make('joker_mobil_origin_points')
                            ->label('Patokan titik hitung')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nama titik')
                                    ->placeholder('SMP 1 Stbd / PLN Cempaka / RSAR')
                                    ->required(),
                                Forms\Components\TextInput::make('lat')
                                    ->label('Latitude')
                                    ->numeric()
                                    ->required(),
                                Forms\Components\TextInput::make('lng')
                                    ->label('Longitude')
                                    ->numeric()
                                    ->required(),
                            ])
                            ->columns(['default' => 1, 'md' => 3])
                            ->addActionLabel('Tambah titik hitung')
                            ->reorderable(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $state = $this->form->getState();

        foreach ($this->settingKeys() as $key) {
            if ($key === 'joker_mobil_origin_points') {
                $points = collect($state[$key] ?? [])
                    ->filter(fn (mixed $point): bool => is_array($point) && filled($point['name'] ?? null) && is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null))
                    ->map(fn (array $point): array => [
                        'name' => trim((string) $point['name']),
                        'lat' => (float) $point['lat'],
                        'lng' => (float) $point['lng'],
                    ])
                    ->values()
                    ->all();

                $settings->set($key, json_encode($points));
                continue;
            }

            $settings->set($key, $state[$key] ?? null);
        }

        Notification::make()
            ->title('CMS Joker Mobil tersimpan')
            ->body('Rumus tarif, setoran, jasa tambahan, dan titik hitung sudah diperbarui.')
            ->success()
            ->send();
    }

    /**
     * @return array<int, string>
     */
    private function settingKeys(): array
    {
        return [
            'joker_mobil_ring1_max_km',
            'joker_mobil_ring1_price',
            'joker_mobil_ring2_max_km',
            'joker_mobil_ring2_per_km',
            'joker_mobil_ring2_add',
            'joker_mobil_ring3_per_km',
            'joker_mobil_ring3_add',
            'joker_mobil_pickup_markup_percent',
            'joker_mobil_deposit_ring1_max_jasa',
            'joker_mobil_deposit_ring1_amount',
            'joker_mobil_deposit_ring2_percent',
            'joker_mobil_round_trip_second_point_percent',
            'joker_mobil_round_trip_free_wait_minutes',
            'joker_mobil_wait_price_per_block',
            'joker_mobil_wait_block_minutes',
            'joker_mobil_night_percent',
            'joker_mobil_helper_fee_increment',
            'joker_mobil_origin_points',
        ];
    }
}
