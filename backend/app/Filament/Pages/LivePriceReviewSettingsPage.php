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
}
