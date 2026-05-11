<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Services\SettingService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MapProviderPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Map Provider';

    protected static ?string $slug = 'map-provider';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.map-provider-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill([
            'map_provider' => $settings->get('map_provider', 'osm'),
            'google_maps_api_key' => $settings->raw('google_maps_api_key')?->value,
            'google_maps_active' => $settings->raw('google_maps_api_key')?->is_active ?? false,
            'mapbox_api_key' => $settings->raw('mapbox_api_key')?->value,
            'mapbox_active' => $settings->raw('mapbox_api_key')?->is_active ?? false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Provider')
                    ->description('Google/Mapbox dipakai hanya jika provider dipilih dan API key aktif. Selain itu otomatis fallback ke OpenStreetMap.')
                    ->schema([
                        Forms\Components\Select::make('map_provider')
                            ->label('Map Provider')
                            ->options([
                                'osm' => 'OpenStreetMap',
                                'google' => 'Google Maps',
                                'mapbox' => 'Mapbox',
                            ])
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('google_maps_api_key')
                            ->password()
                            ->revealable()
                            ->maxLength(500)
                            ->required(fn (Forms\Get $get): bool => $get('map_provider') === 'google'),
                        Forms\Components\Toggle::make('google_maps_active')
                            ->label('Google Maps API Key Active')
                            ->required(fn (Forms\Get $get): bool => $get('map_provider') === 'google'),
                        Forms\Components\TextInput::make('mapbox_api_key')
                            ->password()
                            ->revealable()
                            ->maxLength(500)
                            ->required(fn (Forms\Get $get): bool => $get('map_provider') === 'mapbox'),
                        Forms\Components\Toggle::make('mapbox_active')
                            ->label('Mapbox API Key Active')
                            ->required(fn (Forms\Get $get): bool => $get('map_provider') === 'mapbox'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->form->getState();
        $provider = $data['map_provider'];

        if ($provider === 'google' && (blank($data['google_maps_api_key'] ?? null) || ! ($data['google_maps_active'] ?? false))) {
            Notification::make()
                ->title('Google Maps belum siap')
                ->body('Isi API key dan aktifkan Google Maps API Key sebelum memilih provider Google.')
                ->danger()
                ->send();

            return;
        }

        if ($provider === 'mapbox' && (blank($data['mapbox_api_key'] ?? null) || ! ($data['mapbox_active'] ?? false))) {
            Notification::make()
                ->title('Mapbox belum siap')
                ->body('Isi API key dan aktifkan Mapbox API Key sebelum memilih provider Mapbox.')
                ->danger()
                ->send();

            return;
        }

        $settings->set('map_provider', $provider);
        $settings->set('google_maps_api_key', $data['google_maps_api_key'] ?? null, (bool) ($data['google_maps_active'] ?? false));
        $settings->set('mapbox_api_key', $data['mapbox_api_key'] ?? null, (bool) ($data['mapbox_active'] ?? false));

        Notification::make()
            ->title('Map provider tersimpan')
            ->success()
            ->send();
    }
}
