<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PushNotificationPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Push Notification';

    protected static ?string $slug = 'push-notification';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.push-notification-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === UserRole::Admin;
    }

    public function mount(): void
    {
        $this->form->fill([
            'title' => 'Jojo App',
            'message' => 'Test push notification dari dashboard.',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Send Test Push')
                    ->schema([
                        Forms\Components\Textarea::make('device_token')
                            ->required()
                            ->rows(3),
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(120),
                        Forms\Components\Textarea::make('message')
                            ->required()
                            ->rows(3),
                    ]),
            ])
            ->statePath('data');
    }

    public function send(PushNotificationService $pushNotification): void
    {
        $data = $this->form->getState();

        $result = $pushNotification->send(
            $data['device_token'],
            $data['title'],
            $data['message'],
            ['source' => 'filament_test'],
        );

        Notification::make()
            ->title($result['success'] ? 'Push notification terkirim' : 'Push notification gagal')
            ->body($result['message'] ?? 'Status FCM: '.($result['status'] ?? '-'))
            ->{$result['success'] ? 'success' : 'danger'}()
            ->send();
    }
}
