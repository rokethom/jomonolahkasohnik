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

class AiNlpSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationGroup = 'AI';

    protected static ?string $navigationLabel = 'AI NLP OpenRouter';

    protected static ?string $title = 'AI NLP OpenRouter';

    protected static ?string $slug = 'ai-nlp-settings';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.ai-nlp-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill([
            'ai_assistant_enabled' => $settings->bool('ai_assistant_enabled', false),
            'ai_openrouter_free_auto_enabled' => $settings->bool('ai_openrouter_free_auto_enabled', true),
            'ai_location_learning_openrouter_enabled' => $settings->bool('ai_location_learning_openrouter_enabled', false),
            'ai_model' => $settings->get('ai_model', 'openrouter/free'),
            'ai_base_url' => $settings->get('ai_base_url', 'https://openrouter.ai/api/v1'),
            'ai_max_tokens' => $settings->int('ai_max_tokens', 700),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('AI NLP Parser')
                    ->description('Lapisan NLP untuk memahami teks order customer, typo, alias lokasi, dan riwayat WhatsApp. Default memakai OpenRouter agar bisa memakai model gratis atau model pilihan admin.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('ai_assistant_enabled')
                            ->label('Aktifkan AI NLP parser')
                            ->helperText('Jika mati atau OpenRouter gagal, JOJOBOT tetap memakai parser lokal lama.'),
                        Forms\Components\Toggle::make('ai_openrouter_free_auto_enabled')
                            ->label('Auto switch model free')
                            ->live()
                            ->helperText('Jika aktif, model dikunci ke openrouter/free. Jika nonaktif, admin dapat memilih model sendiri.'),
                        Forms\Components\Toggle::make('ai_location_learning_openrouter_enabled')
                            ->label('AI Location Learning pakai OpenRouter')
                            ->helperText('Aktifkan agar import/paste order lama bisa dibantu NLP untuk menemukan pickup, tujuan, alias, dan POI.'),
                        Forms\Components\Placeholder::make('provider')
                            ->label('Provider aktif')
                            ->content('OpenRouter'),
                        Forms\Components\Select::make('ai_model')
                            ->label('Model NLP')
                            ->options($this->openRouterModelOptions())
                            ->searchable()
                            ->native(false)
                            ->disabled(fn (Forms\Get $get): bool => (bool) $get('ai_openrouter_free_auto_enabled'))
                            ->helperText('Model ini dipakai untuk AI parser order. Auto free akan memakai openrouter/free.'),
                        Forms\Components\TextInput::make('ai_max_tokens')
                            ->label('Max token output')
                            ->numeric()
                            ->minValue(200)
                            ->maxValue(1500)
                            ->default(700),
                        Forms\Components\TextInput::make('ai_base_url')
                            ->label('Base URL OpenRouter')
                            ->default('https://openrouter.ai/api/v1')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('OpenRouter API Key')
                    ->description('Key disimpan terenkripsi di App Settings. Kosongkan field key baru jika tidak ingin mengganti key yang sudah ada.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('openrouter_current')
                            ->label('Key tersimpan')
                            ->content(fn (): string => app(SettingService::class)->mask(app(SettingService::class)->get('openrouter_api_key'))),
                        Forms\Components\TextInput::make('openrouter_api_key')
                            ->label('OpenRouter API Key baru')
                            ->password()
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Flow NLP')
                    ->schema([
                        Forms\Components\Placeholder::make('flow')
                            ->hiddenLabel()
                            ->content('Customer kirim teks order -> parser lokal cek rule/alias -> AI NLP OpenRouter membaca maksud order jika perlu -> hasil JSON dipakai Laravel pricing/order service -> AI logs dan parser memory menyimpan hasil untuk pembelajaran berikutnya.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->form->getState();
        $freeAuto = (bool) ($data['ai_openrouter_free_auto_enabled'] ?? true);

        $settings->set('ai_assistant_enabled', (bool) ($data['ai_assistant_enabled'] ?? false));
        $settings->set('ai_provider', 'openrouter');
        $settings->set('ai_openrouter_free_auto_enabled', $freeAuto);
        $settings->set('ai_location_learning_openrouter_enabled', (bool) ($data['ai_location_learning_openrouter_enabled'] ?? false));
        $settings->set('ai_model', $freeAuto ? 'openrouter/free' : ($data['ai_model'] ?? 'openrouter/auto'));
        $settings->set('ai_base_url', $data['ai_base_url'] ?? 'https://openrouter.ai/api/v1');
        $settings->set('ai_max_tokens', max(200, min(1500, (int) ($data['ai_max_tokens'] ?? 700))));

        if (filled($data['openrouter_api_key'] ?? null)) {
            $settings->set('openrouter_api_key', $data['openrouter_api_key'], true);
        } elseif ($current = $settings->raw('openrouter_api_key')) {
            $current->forceFill(['is_active' => true])->save();
            $settings->clearCache();
        }

        $settings->applyToConfig();

        Notification::make()
            ->title('AI NLP tersimpan')
            ->body('Provider NLP aktif sekarang OpenRouter. Parser tetap fallback ke parser lokal jika API tidak siap.')
            ->success()
            ->send();

        $this->mount($settings);
    }

    /**
     * @return array<string, string>
     */
    private function openRouterModelOptions(): array
    {
        return [
            'openrouter/free' => 'OpenRouter Free Router - auto pilih model gratis',
            'openrouter/auto' => 'OpenRouter Auto Router - pilih model terbaik otomatis',
            'openrouter/owl-alpha' => 'Owl Alpha - free',
            'inclusionai/ring-2.6-1t:free' => 'inclusionAI Ring 2.6 1T - free',
            'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free' => 'NVIDIA Nemotron 3 Nano Omni - free',
            'baidu/cobuddy:free' => 'Baidu CoBuddy - free',
            'poolside/laguna-xs.2:free' => 'Poolside Laguna XS.2 - free',
            'poolside/laguna-m.1:free' => 'Poolside Laguna M.1 - free',
        ];
    }
}
