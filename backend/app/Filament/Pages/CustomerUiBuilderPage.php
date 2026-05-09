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

class CustomerUiBuilderPage extends Page implements HasForms
{
    use InteractsWithForms;

    private const SETTING_KEY = 'customer_ui_builder_simulation';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Home CMS';

    protected static ?string $navigationLabel = 'Customer UI Builder';

    protected static ?string $title = 'Customer UI Builder';

    protected static ?string $slug = 'customer-ui-builder';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.customer-ui-builder-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill($this->storedSimulation($settings));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Simulation Mode')
                    ->description('Builder ini hanya simulasi backend. Save di sini tidak mengubah tampilan FE customer sampai integrasi render FE dibuat.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('page')
                            ->label('Preview page')
                            ->options([
                                'home' => 'Home Customer',
                                'chat_order' => 'Chat Order',
                                'history' => 'History Order',
                                'profile' => 'Profile Customer',
                            ])
                            ->default('home')
                            ->native(false)
                            ->live()
                            ->required(),
                        Forms\Components\TextInput::make('preview_customer_name')
                            ->label('Nama customer mock')
                            ->default('Jomono')
                            ->maxLength(80)
                            ->live(onBlur: false),
                    ]),
                Forms\Components\Section::make('Block Layout')
                    ->description('Drag / reorder block untuk simulasi penempatan komponen. Gunakan tombol panah pada repeater jika drag tidak nyaman di mobile.')
                    ->schema([
                        Forms\Components\Repeater::make('blocks')
                            ->hiddenLabel()
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => ($state['enabled'] ?? true) ? ($state['label'] ?? $state['type'] ?? 'Block') : 'Hidden: '.($state['label'] ?? $state['type'] ?? 'Block'))
                            ->schema([
                                Forms\Components\Grid::make(12)
                                    ->schema([
                                        Forms\Components\Toggle::make('enabled')
                                            ->label('Visible')
                                            ->default(true)
                                            ->live()
                                            ->columnSpan(2),
                                        Forms\Components\Select::make('type')
                                            ->label('Block')
                                            ->options($this->blockOptions())
                                            ->native(false)
                                            ->required()
                                            ->live()
                                            ->columnSpan(4),
                                        Forms\Components\TextInput::make('label')
                                            ->label('Label')
                                            ->required()
                                            ->maxLength(80)
                                            ->live(onBlur: false)
                                            ->columnSpan(3),
                                        Forms\Components\Select::make('style')
                                            ->label('Style')
                                            ->options([
                                                'soft' => 'Soft',
                                                'solid' => 'Solid',
                                                'outline' => 'Outline',
                                                'danger' => 'Danger',
                                            ])
                                            ->default('soft')
                                            ->native(false)
                                            ->live()
                                            ->columnSpan(3),
                                        Forms\Components\TextInput::make('subtitle')
                                            ->label('Subtitle / hint')
                                            ->maxLength(140)
                                            ->live(onBlur: false)
                                            ->columnSpan(8),
                                        Forms\Components\Select::make('target')
                                            ->label('Action target')
                                            ->options([
                                                'none' => 'None',
                                                'order' => 'Open order/chat',
                                                'history' => 'Open history',
                                                'profile' => 'Open profile',
                                                'cs' => 'Chat CS',
                                                'logout' => 'Logout',
                                            ])
                                            ->default('none')
                                            ->native(false)
                                            ->live()
                                            ->columnSpan(4),
                                    ]),
                            ])
                            ->default($this->defaultBlocks())
                            ->addActionLabel('Tambah block simulasi')
                            ->columns(1),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->normalizedState($this->form->getState());

        $settings->set(self::SETTING_KEY, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), true, [
            'mode' => 'simulation_only',
            'does_not_affect_frontend' => true,
        ]);

        $this->form->fill($data);

        Notification::make()
            ->title('Simulasi UI tersimpan')
            ->body('Data tersimpan sebagai draft simulasi dan belum mengubah FE customer.')
            ->success()
            ->send();
    }

    public function resetSimulation(): void
    {
        $this->form->fill($this->defaultSimulation());

        Notification::make()
            ->title('Simulasi dikembalikan ke default')
            ->body('Klik Simpan Draft Simulasi jika ingin menyimpan susunan default ini.')
            ->info()
            ->send();
    }

    public function getBlocks(): array
    {
        return collect($this->data['blocks'] ?? [])
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['enabled'] ?? true))
            ->values()
            ->all();
    }

    public function getPreviewPageLabel(): string
    {
        return [
            'home' => 'Home Customer',
            'chat_order' => 'Chat Order',
            'history' => 'History Order',
            'profile' => 'Profile Customer',
        ][$this->data['page'] ?? 'home'] ?? 'Home Customer';
    }

    private function storedSimulation(SettingService $settings): array
    {
        $raw = $settings->get(self::SETTING_KEY);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return $this->normalizedState(is_array($decoded) ? $decoded : $this->defaultSimulation());
    }

    private function defaultSimulation(): array
    {
        return [
            'page' => 'home',
            'preview_customer_name' => 'Jomono',
            'blocks' => $this->defaultBlocks(),
        ];
    }

    private function defaultBlocks(): array
    {
        return [
            [
                'enabled' => true,
                'type' => 'hero_greeting',
                'label' => 'Greeting Header',
                'subtitle' => 'Hai {customer}, Selamat Datang!',
                'style' => 'solid',
                'target' => 'order',
            ],
            [
                'enabled' => true,
                'type' => 'cms_slider',
                'label' => 'Section Slider',
                'subtitle' => 'Render dari Banner/Home Section slider',
                'style' => 'soft',
                'target' => 'none',
            ],
            [
                'enabled' => true,
                'type' => 'service_menu',
                'label' => 'Menu Layanan',
                'subtitle' => 'Belanja, Delivery, Kurir, Ojek, Gift, Joker Mobil',
                'style' => 'outline',
                'target' => 'order',
            ],
            [
                'enabled' => true,
                'type' => 'promo_section',
                'label' => 'Section Promo',
                'subtitle' => 'Render dari Home Item section promo',
                'style' => 'solid',
                'target' => 'none',
            ],
            [
                'enabled' => true,
                'type' => 'announcement',
                'label' => 'Announcement',
                'subtitle' => 'Promo special / info aktif dari CMS',
                'style' => 'soft',
                'target' => 'none',
            ],
            [
                'enabled' => true,
                'type' => 'bottom_nav',
                'label' => 'Bottom Navigation',
                'subtitle' => 'Home, Order, Chat, Profile',
                'style' => 'outline',
                'target' => 'none',
            ],
        ];
    }

    private function normalizedState(array $state): array
    {
        $knownBlocks = array_keys($this->blockOptions());

        return [
            'page' => in_array(($state['page'] ?? 'home'), ['home', 'chat_order', 'history', 'profile'], true) ? $state['page'] : 'home',
            'preview_customer_name' => trim((string) ($state['preview_customer_name'] ?? 'Jomono')) ?: 'Jomono',
            'blocks' => collect($state['blocks'] ?? $this->defaultBlocks())
                ->filter(fn (mixed $block): bool => is_array($block))
                ->map(fn (array $block): array => [
                    'enabled' => (bool) ($block['enabled'] ?? true),
                    'type' => in_array(($block['type'] ?? ''), $knownBlocks, true) ? $block['type'] : 'service_menu',
                    'label' => trim((string) ($block['label'] ?? 'Block')) ?: 'Block',
                    'subtitle' => trim((string) ($block['subtitle'] ?? '')),
                    'style' => in_array(($block['style'] ?? 'soft'), ['soft', 'solid', 'outline', 'danger'], true) ? $block['style'] : 'soft',
                    'target' => in_array(($block['target'] ?? 'none'), ['none', 'order', 'history', 'profile', 'cs', 'logout'], true) ? $block['target'] : 'none',
                ])
                ->values()
                ->all(),
        ];
    }

    private function blockOptions(): array
    {
        return [
            'hero_greeting' => 'Hero greeting',
            'cms_slider' => 'CMS slider',
            'service_menu' => 'Service menu',
            'promo_section' => 'Promo section',
            'announcement' => 'Announcement',
            'quick_action' => 'Quick action button',
            'history_shortcut' => 'History shortcut',
            'profile_card' => 'Profile card',
            'logout_button' => 'Logout button',
            'bottom_nav' => 'Bottom navigation',
        ];
    }
}
