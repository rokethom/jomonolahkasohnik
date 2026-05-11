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

class OAuthSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'OAuth Settings';

    protected static ?string $slug = 'oauth-settings';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.oauth-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill([
            'google_oauth_enabled' => $settings->bool('google_oauth_enabled'),
            'google_oauth_client_id' => $settings->raw('google_oauth_client_id')?->value,
            'google_oauth_secret' => $settings->raw('google_oauth_secret')?->value,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Google OAuth')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('google_oauth_enabled')
                            ->label('Enable Google Login')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('google_oauth_client_id')
                            ->label('Client ID')
                            ->maxLength(500),
                        Forms\Components\TextInput::make('google_oauth_secret')
                            ->label('Client Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(500),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->form->getState();
        $enabled = (bool) ($data['google_oauth_enabled'] ?? false);

        $settings->set('google_oauth_enabled', $enabled);
        $settings->set('google_oauth_client_id', $data['google_oauth_client_id'] ?? null, $enabled);
        $settings->set('google_oauth_secret', $data['google_oauth_secret'] ?? null, $enabled);

        Notification::make()
            ->title('OAuth settings tersimpan')
            ->success()
            ->send();
    }
}
