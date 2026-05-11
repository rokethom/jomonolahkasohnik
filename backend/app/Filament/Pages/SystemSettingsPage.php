<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Services\SettingService;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SystemSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    private const DEFAULT_ASSIGN_DRIVER_ROLES = ['operator', 'eksekutor'];

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'System Settings';

    protected static ?string $title = 'App Settings';

    protected static ?string $slug = 'app-settings-cms';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.system-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, [UserRole::Admin, UserRole::GM, UserRole::Manager, UserRole::SPV], true);
    }

    public function mount(SettingService $settings): void
    {
        $this->form->fill([
            'google_maps_current' => $settings->mask($settings->get('google_maps_api_key')),
            'mapbox_current' => $settings->mask($settings->get('mapbox_api_key')),
            'fcm_current' => $settings->mask($settings->get('fcm_server_key')),
            'fcm_service_account_current' => $settings->mask($settings->get('fcm_service_account_json')),
            'google_oauth_secret_current' => $settings->mask($settings->get('google_oauth_secret')),
            'openai_current' => $settings->mask($settings->get('openai_api_key')),
            'kimi_current' => $settings->mask($settings->get('kimi_api_key')),
            'blackbox_current' => $settings->mask($settings->get('blackbox_api_key')),
            'openrouter_current' => $settings->mask($settings->get('openrouter_api_key')),
            'hermes_current' => $settings->mask($settings->get('hermes_api_key')),
            'google_maps_active' => $settings->raw('google_maps_api_key')?->is_active ?? false,
            'mapbox_active' => $settings->raw('mapbox_api_key')?->is_active ?? false,
            'fcm_active' => $settings->raw('fcm_server_key')?->is_active ?? false,
            'fcm_service_account_active' => $settings->raw('fcm_service_account_json')?->is_active ?? false,
            'firebase_vapid_key' => $settings->get('firebase_vapid_key'),
            'firebase_vapid_active' => $settings->raw('firebase_vapid_key')?->is_active ?? false,
            'firebase_web_config' => $settings->get('firebase_web_config'),
            'firebase_web_config_active' => $settings->raw('firebase_web_config')?->is_active ?? false,
            'openai_active' => $settings->raw('openai_api_key')?->is_active ?? false,
            'kimi_active' => $settings->raw('kimi_api_key')?->is_active ?? false,
            'blackbox_active' => $settings->raw('blackbox_api_key')?->is_active ?? false,
            'openrouter_active' => $settings->raw('openrouter_api_key')?->is_active ?? false,
            'hermes_active' => $settings->raw('hermes_api_key')?->is_active ?? false,
            'map_provider' => $settings->get('map_provider', 'osm'),
            'map_active' => $settings->raw('map_provider')?->is_active ?? true,
            'location_log_cleanup_enabled' => $settings->bool('location_log_cleanup_enabled', true),
            'location_log_retention_days' => $settings->int('location_log_retention_days', 14),
            'location_log_suspicious_retention_days' => $settings->int('location_log_suspicious_retention_days', 30),
            'location_log_cleanup_batch_limit' => $settings->int('location_log_cleanup_batch_limit', 1000),
            'google_oauth_enabled' => $settings->bool('google_oauth_enabled'),
            'google_oauth_client_id' => $settings->get('google_oauth_client_id'),
            'multi_order_enabled' => $settings->bool('multi_order_enabled', false),
            'max_multi_order' => $settings->int('max_multi_order', 3),
            'order_close_enabled' => $settings->bool('order_close_enabled', true),
            'order_close_start' => $settings->get('order_close_start', '01:00') ?: '01:00',
            'order_close_end' => $settings->get('order_close_end', '05:00') ?: '05:00',
            'order_close_message' => $settings->get('order_close_message', 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.') ?: 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.',
            'multi_crew_auto_cancel_enabled' => $settings->bool('multi_crew_auto_cancel_enabled', true),
            'multi_crew_auto_cancel_minutes' => $settings->int('multi_crew_auto_cancel_minutes', 7),
            'multi_crew_auto_cancel_message' => $settings->get('multi_crew_auto_cancel_message', 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.') ?: 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.',
            'driver_daily_priority_enabled' => $settings->bool('driver_daily_priority_enabled', true),
            'driver_daily_priority_hold_minutes' => $settings->int('driver_daily_priority_hold_minutes', 3),
            'driver_daily_priority_windows' => $this->dailyPriorityWindows($settings),
            'night_tariff_enabled' => $settings->bool('night_tariff_enabled', true),
            'night_tariff_rules' => $this->nightTariffRules($settings),
            'assign_driver_allowed_roles' => $this->assignDriverAllowedRoles($settings),
            'payment_cash_enabled' => true,
            'payment_transfer_enabled' => true,
            'payment_bank_accounts' => $this->transferAccounts($settings),
            'qris_image' => $this->normalizeUploadState($settings->get('payment_qris_image')),
            'complaint_whatsapp_number' => $settings->get('complaint_whatsapp_number', '6281299232918'),
            'ai_assistant_enabled' => $settings->bool('ai_assistant_enabled', false),
            'ai_provider' => $settings->get('ai_provider', 'openai'),
            'ai_model' => $settings->get('ai_model'),
            'ai_model_custom' => null,
            'ai_base_url' => $settings->get('ai_base_url'),
            'ai_max_tokens' => $settings->int('ai_max_tokens', 700),
            'hermes_enabled' => $settings->bool('hermes_enabled', false),
            'hermes_provider' => $settings->get('hermes_provider', 'openai_compatible'),
            'hermes_model' => $settings->get('hermes_model', 'nousresearch/hermes-3-llama-3.1-405b'),
            'hermes_base_url' => $settings->get('hermes_base_url'),
            'hermes_max_tokens' => $settings->int('hermes_max_tokens', 1800),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tabs\Tab::make('API Keys')
                            ->icon('heroicon-o-key')
                            ->visible(fn (): bool => auth()->user()?->role === UserRole::Admin)
                            ->schema([
                                Forms\Components\Section::make('Google Maps')
                                    ->description('Digunakan untuk menampilkan Google Maps dan pencarian lokasi jika provider Google dipilih.')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('google_maps_current')
                                            ->label('Key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('google_maps_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('google_maps_active')
                                            ->label('Aktif'),
                                        Forms\Components\TextInput::make('google_maps_api_key')
                                            ->label('Google Maps API Key baru')
                                            ->password()
                                            ->helperText('Kosongkan jika tidak ingin mengganti key yang tersimpan.')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Mapbox')
                                    ->description('Digunakan untuk provider Mapbox dan tile map premium.')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('mapbox_current')
                                            ->label('Key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('mapbox_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('mapbox_active')
                                            ->label('Aktif'),
                                        Forms\Components\TextInput::make('mapbox_api_key')
                                            ->label('Mapbox API Key baru')
                                            ->password()
                                            ->helperText('Kosongkan jika tidak ingin mengganti key yang tersimpan.')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Firebase Cloud Messaging')
                                    ->description('Digunakan untuk push notification customer/driver. Untuk FCM terbaru disarankan memakai Service Account JSON + Web Config + VAPID Key.')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('fcm_current')
                                            ->label('Legacy server key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('fcm_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('fcm_active')
                                            ->label('Legacy aktif'),
                                        Forms\Components\TextInput::make('fcm_server_key')
                                            ->label('Legacy FCM Server Key baru')
                                            ->password()
                                            ->helperText('Legacy API lama. Jika Service Account JSON diisi, sistem memakai HTTP v1.')
                                            ->maxLength(1000)
                                            ->columnSpanFull(),
                                        Forms\Components\Placeholder::make('fcm_service_account_current')
                                            ->label('Service Account tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('fcm_service_account_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('fcm_service_account_active')
                                            ->label('HTTP v1 aktif'),
                                        Forms\Components\Textarea::make('fcm_service_account_json')
                                            ->label('Service Account JSON baru')
                                            ->rows(5)
                                            ->helperText('Paste isi file JSON penuh dari Firebase Service Account. Wajib ada project_id, client_email, dan private_key. Kosongkan jika tidak ingin mengganti.')
                                            ->columnSpanFull(),
                                        Forms\Components\Toggle::make('firebase_web_config_active')
                                            ->label('Web config aktif'),
                                        Forms\Components\Toggle::make('firebase_vapid_active')
                                            ->label('VAPID aktif'),
                                        Forms\Components\Textarea::make('firebase_web_config')
                                            ->label('Firebase Web Config JSON')
                                            ->rows(5)
                                            ->helperText('Berisi apiKey, authDomain, projectId, messagingSenderId, appId. Dikirim ke FE agar token FCM bisa dibuat.')
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('firebase_vapid_key')
                                            ->label('Web Push VAPID Key')
                                            ->minLength(70)
                                            ->helperText('Firebase Console > Project Settings > Cloud Messaging > Web Push certificates. Pakai public key, bukan server key/API key.')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tabs\Tab::make('AI Assistant')
                            ->icon('heroicon-o-sparkles')
                            ->visible(fn (): bool => auth()->user()?->role === UserRole::Admin)
                            ->schema([
                                Forms\Components\Section::make('Smart Assistant Layer')
                                    ->description('AI hanya dipakai untuk membaca maksud dan ekstrak data order. Harga, validasi GPS, limit order, dan create order tetap memakai Laravel service existing.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('ai_assistant_enabled')
                                            ->label('Enable AI parser')
                                            ->helperText('Jika mati atau API gagal, sistem otomatis fallback ke parser lama.'),
                                        Forms\Components\Select::make('ai_provider')
                                            ->label('Provider aktif')
                                            ->options([
                                                'openai' => 'OpenAI',
                                                'kimi' => 'Kimi / Moonshot',
                                                'blackbox' => 'Blackbox AI',
                                                'openrouter' => 'OpenRouter',
                                            ])
                                            ->live()
                                            ->native(false)
                                            ->required(),
                                        Forms\Components\Select::make('ai_model')
                                            ->label('Model parser')
                                            ->options(fn (Forms\Get $get): array => $this->aiModelOptions((string) $get('ai_provider')))
                                            ->searchable()
                                            ->native(false)
                                            ->helperText('Untuk OpenRouter, gunakan OpenRouter Free Router atau isi custom model ID. Sistem akan mencoba model pilihan admin lebih dulu lalu fallback ke router gratis.'),
                                        Forms\Components\TextInput::make('ai_model_custom')
                                            ->label('Custom model ID')
                                            ->placeholder('contoh: openrouter/free')
                                            ->helperText('Opsional. Jika diisi, nilai ini menggantikan pilihan Model parser tanpa perlu ubah kode.'),
                                        Forms\Components\TextInput::make('ai_max_tokens')
                                            ->label('Max token output')
                                            ->numeric()
                                            ->minValue(200)
                                            ->maxValue(1500)
                                            ->default(700)
                                            ->helperText('Batasi output supaya biaya dan latency parser tetap terkendali.'),
                                        Forms\Components\TextInput::make('ai_base_url')
                                            ->label('Base URL override')
                                            ->helperText('Kosongkan untuk default provider. OpenRouter default: https://openrouter.ai/api/v1')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\View::make('filament.forms.components.ai-parser-flow')
                                    ->columnSpanFull(),
                                Forms\Components\Section::make('Hermes Engineering Assistant')
                                    ->description('Hermes adalah Internal AI Engineering Assistant untuk membaca ringkasan source code, logs, route, schema, dan metrik. Jalurnya dipisah dari AI parser order.')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('hermes_enabled')
                                            ->label('Enable Hermes')
                                            ->helperText('Jika aktif, menu Hermes Engineering Center bisa menjalankan analisa read-only.'),
                                        Forms\Components\Select::make('hermes_provider')
                                            ->label('Provider Hermes')
                                            ->options([
                                                'openai_compatible' => 'OpenAI-compatible / self-hosted Hermes',
                                                'openrouter' => 'OpenRouter',
                                                'openai' => 'OpenAI-compatible default OpenAI URL',
                                            ])
                                            ->native(false)
                                            ->required(),
                                        Forms\Components\TextInput::make('hermes_model')
                                            ->label('Hermes model ID')
                                            ->placeholder('nousresearch/hermes-3-llama-3.1-405b')
                                            ->helperText('Gunakan model Hermes dari provider pilihan. Dibuat configurable agar tidak terkunci jika daftar model berubah.')
                                            ->maxLength(180)
                                            ->required(),
                                        Forms\Components\TextInput::make('hermes_max_tokens')
                                            ->label('Max token report')
                                            ->numeric()
                                            ->minValue(600)
                                            ->maxValue(4000)
                                            ->default(1800),
                                        Forms\Components\TextInput::make('hermes_base_url')
                                            ->label('Hermes base URL')
                                            ->placeholder('https://openrouter.ai/api/v1 atau endpoint self-hosted')
                                            ->helperText('Wajib untuk provider OpenAI-compatible/self-hosted. Kosongkan jika memakai provider OpenRouter/OpenAI default.')
                                            ->columnSpanFull(),
                                        Forms\Components\Placeholder::make('hermes_current')
                                            ->label('Hermes key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('hermes_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('hermes_active')
                                            ->label('Hermes key aktif'),
                                        Forms\Components\TextInput::make('hermes_api_key')
                                            ->label('Hermes API Key baru')
                                            ->password()
                                            ->helperText('Kosongkan jika tidak ingin mengganti key. Key Hermes sengaja dipisah dari OpenRouter parser.')
                                            ->maxLength(1500)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('OpenAI')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('openai_current')
                                            ->label('Key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('openai_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('openai_active')
                                            ->label('Aktif'),
                                        Forms\Components\TextInput::make('openai_api_key')
                                            ->label('OpenAI API Key baru')
                                            ->password()
                                            ->helperText('Kosongkan jika tidak ingin mengganti key yang tersimpan.')
                                            ->maxLength(1000)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Kimi / Moonshot')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('kimi_current')
                                            ->label('Key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('kimi_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('kimi_active')
                                            ->label('Aktif'),
                                        Forms\Components\TextInput::make('kimi_api_key')
                                            ->label('Kimi API Key baru')
                                            ->password()
                                            ->helperText('Default base URL: https://api.moonshot.cn/v1')
                                            ->maxLength(1000)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Blackbox AI')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('blackbox_current')
                                            ->label('Key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('blackbox_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('blackbox_active')
                                            ->label('Aktif'),
                                        Forms\Components\TextInput::make('blackbox_api_key')
                                            ->label('Blackbox API Key baru')
                                            ->password()
                                            ->helperText('Jika endpoint akun berbeda, isi Base URL override di atas.')
                                            ->maxLength(1000)
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('OpenRouter')
                                    ->description('OpenRouter bersifat OpenAI-compatible. Gunakan model :free untuk parser murah, lalu monitor limit/rate dari dashboard OpenRouter.')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Placeholder::make('openrouter_current')
                                            ->label('Key tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('openrouter_current') ?: 'Belum diisi'),
                                        Forms\Components\Toggle::make('openrouter_active')
                                            ->label('Aktif'),
                                        Forms\Components\TextInput::make('openrouter_api_key')
                                            ->label('OpenRouter API Key baru')
                                            ->password()
                                            ->helperText('Kosongkan jika tidak ingin mengganti key yang tersimpan.')
                                            ->maxLength(1000)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tabs\Tab::make('Map')
                            ->icon('heroicon-o-map')
                            ->visible(fn (): bool => auth()->user()?->role === UserRole::Admin)
                            ->schema([
                                Forms\Components\Section::make('Map Settings')
                                    ->description('Provider aktif akan dikirim ke aplikasi customer. Jika provider berbayar tidak valid, sistem fallback ke OpenStreetMap.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Select::make('map_provider')
                                            ->label('Provider')
                                            ->options([
                                                'google' => 'Google Maps',
                                                'mapbox' => 'Mapbox',
                                                'osm' => 'OpenStreetMap',
                                            ])
                                            ->required()
                                            ->helperText('OpenStreetMap adalah fallback gratis bawaan.'),
                                        Forms\Components\Toggle::make('map_active')
                                            ->label('Status aktif')
                                            ->helperText('Matikan untuk memakai fallback .env/default.'),
                                    ]),
                                Forms\Components\Section::make('Auto Cleanup Location Logs')
                                    ->description('Menghapus riwayat GPS lama secara bertahap agar tabel location_logs tidak membebani server. Data lokasi terakhir user/driver tetap tersimpan di profile/log terbaru.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('location_log_cleanup_enabled')
                                            ->label('Aktifkan cleanup otomatis')
                                            ->helperText('Scheduler berjalan harian pukul 02:30 server. Matikan hanya jika sedang investigasi log.'),
                                        Forms\Components\TextInput::make('location_log_cleanup_batch_limit')
                                            ->label('Maksimal hapus per run')
                                            ->numeric()
                                            ->minValue(100)
                                            ->maxValue(10000)
                                            ->default(1000)
                                            ->helperText('Batch kecil lebih aman untuk VPS. Rekomendasi 1000-5000.'),
                                        Forms\Components\TextInput::make('location_log_retention_days')
                                            ->label('Retensi log normal')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(365)
                                            ->suffix('hari')
                                            ->default(14)
                                            ->helperText('Log normal adalah GPS yang tidak suspicious/mock. Rekomendasi 14 hari.'),
                                        Forms\Components\TextInput::make('location_log_suspicious_retention_days')
                                            ->label('Retensi suspicious/mock')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(730)
                                            ->suffix('hari')
                                            ->default(30)
                                            ->helperText('Log suspicious/mock disimpan lebih lama untuk investigasi. Nilai akan dipaksa minimal sama dengan retensi normal.'),
                                        Forms\Components\Placeholder::make('location_log_cleanup_flow')
                                            ->label('Flow readonly')
                                            ->content('Normal log > retensi normal akan dihapus. Suspicious/mock > retensi suspicious akan dihapus. Penghapusan memakai batch agar database tidak spike.')
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tabs\Tab::make('Multi Order')
                            ->icon('heroicon-o-arrows-right-left')
                            ->schema([
                                Forms\Components\Section::make('Direction-Based Multi Order')
                                    ->description('Driver dapat menerima lebih dari satu order hanya jika arah tujuan masih searah.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('multi_order_enabled')
                                            ->label('Enable Multi Order')
                                            ->helperText('Jika nonaktif, driver hanya bisa membawa satu order aktif.'),
                                        Forms\Components\TextInput::make('max_multi_order')
                                            ->label('Max Order')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(3)
                                            ->default(3)
                                            ->required()
                                            ->helperText('Batas sistem saat ini maksimal 3 order.'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Operasional Order')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                Forms\Components\Section::make('Jam Operasional Order')
                                    ->description('Sistem menolak order customer pada rentang tutup dan menampilkan popup informasi di aplikasi customer.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('order_close_enabled')
                                            ->label('Aktifkan close order otomatis')
                                            ->helperText('Jika aktif, order ditutup pada jam close sampai jam buka.'),
                                        Forms\Components\TextInput::make('order_close_start')
                                            ->label('Jam close')
                                            ->type('time')
                                            ->required()
                                            ->helperText('Default 01:00. Format 24 jam.'),
                                        Forms\Components\TextInput::make('order_close_end')
                                            ->label('Jam buka')
                                            ->type('time')
                                            ->required()
                                            ->helperText('Default 05:00. Format 24 jam.'),
                                        Forms\Components\Textarea::make('order_close_message')
                                            ->label('Pesan popup customer')
                                            ->rows(3)
                                            ->helperText('Gunakan {start} dan {end} untuk menampilkan jam otomatis.')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Auto-cancel Multi Crew')
                                    ->description('Timeout khusus order multi-crew setelah rider menerima order tetapi helper belum menerima slot. Ini terpisah dari auto-cancel cari driver 10 menit.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('multi_crew_auto_cancel_enabled')
                                            ->label('Aktifkan auto-cancel multi-crew')
                                            ->helperText('Jika nonaktif, order akan tetap menunggu helper sampai admin/driver menyelesaikan manual.'),
                                        Forms\Components\TextInput::make('multi_crew_auto_cancel_minutes')
                                            ->label('Batas tunggu helper')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(180)
                                            ->suffix('menit')
                                            ->required()
                                            ->helperText('Dihitung sejak rider menerima order. Contoh: 7 menit.'),
                                        Forms\Components\Textarea::make('multi_crew_auto_cancel_message')
                                            ->label('Pesan customer')
                                            ->rows(3)
                                            ->helperText('Gunakan {order_code}, {minutes}, {helper_label}, {driver_name}.')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Driver Daily Priority')
                                    ->description('Driver yang pertama kali OFFLINE ke ONLINE pada hari berjalan masuk queue prioritas 1 order jika tetap memenuhi syarat area, layanan, setoran, dan suspend. Prioritas hanya 1 kali per hari Asia/Jakarta.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('driver_daily_priority_enabled')
                                            ->label('Aktifkan prioritas harian driver')
                                            ->helperText('Jika aktif, order baru ditahan sebentar untuk driver prioritas sebelum distribusi normal.'),
                                        Forms\Components\TextInput::make('driver_daily_priority_hold_minutes')
                                            ->label('Durasi tahan prioritas')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(60)
                                            ->suffix('menit')
                                            ->required()
                                            ->helperText('Setelah durasi ini lewat, order kembali tampil untuk driver eligible normal agar tidak macet.'),
                                        Forms\Components\Repeater::make('driver_daily_priority_windows')
                                            ->label('Jam aktif prioritas')
                                            ->schema([
                                                Forms\Components\TimePicker::make('start')
                                                    ->label('Mulai')
                                                    ->seconds(false)
                                                    ->required(),
                                                Forms\Components\TimePicker::make('end')
                                                    ->label('Selesai')
                                                    ->seconds(false)
                                                    ->required(),
                                            ])
                                            ->columns(2)
                                            ->default($this->defaultDailyPriorityWindows())
                                            ->addActionLabel('Tambah jam aktif')
                                            ->helperText('Contoh: 05:00-11:00 dan 13:00-17:00. Di luar jam ini, prioritas harian tidak dibuat dan tidak menahan order.')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Tarif Jam Malam')
                                    ->description('Tambahan tarif dihitung dari tarif dasar sesuai jam dan area branch. Rule area kosong berlaku global.')
                                    ->schema([
                                        Forms\Components\Toggle::make('night_tariff_enabled')
                                            ->label('Aktifkan tarif jam malam'),
                                        Forms\Components\Repeater::make('night_tariff_rules')
                                            ->label('Rule tarif malam')
                                            ->schema([
                                                Forms\Components\TextInput::make('area')
                                                    ->label('Area')
                                                    ->placeholder('Kosong = semua area')
                                                    ->maxLength(80),
                                                Forms\Components\TextInput::make('start')
                                                    ->label('Mulai')
                                                    ->type('time')
                                                    ->required(),
                                                Forms\Components\TextInput::make('end')
                                                    ->label('Sampai')
                                                    ->type('time')
                                                    ->required(),
                                                Forms\Components\TextInput::make('percent')
                                                    ->label('Tambahan')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->maxValue(200)
                                                    ->suffix('%')
                                                    ->required(),
                                            ])
                                            ->columns(4)
                                            ->defaultItems(0)
                                            ->addActionLabel('Tambah rule tarif malam')
                                            ->reorderable()
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Assign Driver Order')
                                    ->description('Atur role manajemen yang boleh memilih driver langsung dari Order Operations. Admin dan GM selalu bisa memakai fitur ini agar CMS tidak terkunci.')
                                    ->schema([
                                        Forms\Components\CheckboxList::make('assign_driver_allowed_roles')
                                            ->label('Role yang boleh assign driver')
                                            ->options([
                                                'manager' => 'Manager',
                                                'spv' => 'SPV',
                                                'operator' => 'Operator',
                                                'eksekutor' => 'Eksekutor',
                                            ])
                                            ->columns(2)
                                            ->bulkToggleable()
                                            ->helperText('Role yang tidak dicentang tetap bisa melihat order sesuai hak aksesnya, tetapi tidak bisa melihat kandidat driver, assign driver, atau broadcast driver.'),
                                    ]),
                            ]),
                        Tabs\Tab::make('Payment & Support')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Forms\Components\Section::make('Metode Pembayaran Customer')
                                    ->description('Pilihan ini dikirim ke aplikasi customer saat membuat order.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('payment_cash_enabled')
                                            ->label('Pembayaran Cash')
                                            ->default(true),
                                        Forms\Components\Toggle::make('payment_transfer_enabled')
                                            ->label('Pembayaran Transfer')
                                            ->default(true),
                                        Forms\Components\Repeater::make('payment_bank_accounts')
                                            ->label('Rekening Transfer')
                                            ->schema([
                                                Forms\Components\TextInput::make('bank')
                                                    ->label('Bank')
                                                    ->placeholder('BCA / BRI / Mandiri')
                                                    ->maxLength(80)
                                                    ->required(),
                                                Forms\Components\TextInput::make('account_name')
                                                    ->label('Nama Rekening')
                                                    ->maxLength(120)
                                                    ->required(),
                                                Forms\Components\TextInput::make('account_number')
                                                    ->label('Nomor Rekening')
                                                    ->maxLength(80)
                                                    ->required(),
                                            ])
                                            ->columns(3)
                                            ->defaultItems(1)
                                            ->addActionLabel('Tambah rekening')
                                            ->reorderable()
                                            ->columnSpanFull(),
                                        Forms\Components\FileUpload::make('qris_image')
                                            ->label('Gambar QRIS statis')
                                            ->disk('public')
                                            ->directory('settings/payment')
                                            ->visibility('public')
                                            ->image()
                                            ->imagePreviewHeight('220')
                                            ->maxSize(2048)
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                            ->downloadable()
                                            ->openable()
                                            ->helperText('Opsional. Pakai gambar QRIS aplikasi selama payment gateway belum dipakai.')
                                            ->columnSpanFull(),
                                    ]),
                                Forms\Components\Section::make('Keluhan Customer')
                                    ->description('Nomor WhatsApp untuk menu Laporkan Keluhan di aplikasi customer.')
                                    ->schema([
                                        Forms\Components\TextInput::make('complaint_whatsapp_number')
                                            ->label('Nomor WhatsApp')
                                            ->placeholder('6281299232918')
                                            ->maxLength(30)
                                            ->required(),
                                    ]),
                            ]),
                        Tabs\Tab::make('OAuth')
                            ->icon('heroicon-o-lock-closed')
                            ->visible(fn (): bool => auth()->user()?->role === UserRole::Admin)
                            ->schema([
                                Forms\Components\Section::make('Google Login')
                                    ->description('Kontrol tombol login Google di aplikasi customer.')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\Toggle::make('google_oauth_enabled')
                                            ->label('Enable Google Login')
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('google_oauth_client_id')
                                            ->label('Client ID')
                                            ->helperText('Client ID dari Google Cloud Console.')
                                            ->maxLength(500),
                                        Forms\Components\Placeholder::make('google_oauth_secret_current')
                                            ->label('Client Secret tersimpan')
                                            ->content(fn (Forms\Get $get): string => $get('google_oauth_secret_current') ?: 'Belum diisi'),
                                        Forms\Components\TextInput::make('google_oauth_secret')
                                            ->label('Client Secret baru')
                                            ->password()
                                            ->helperText('Kosongkan jika tidak ingin mengganti secret.')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(SettingService $settings): void
    {
        $data = $this->form->getState();

        $settings->set('multi_order_enabled', (bool) ($data['multi_order_enabled'] ?? false));
        $settings->set('max_multi_order', max(1, min(3, (int) ($data['max_multi_order'] ?? 3))));
        $settings->set('order_close_enabled', (bool) ($data['order_close_enabled'] ?? true));
        $settings->set('order_close_start', $this->normalizeTime((string) ($data['order_close_start'] ?? '01:00')));
        $settings->set('order_close_end', $this->normalizeTime((string) ($data['order_close_end'] ?? '05:00')));
        $settings->set('order_close_message', $data['order_close_message'] ?? 'Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.');
        $settings->set('multi_crew_auto_cancel_enabled', (bool) ($data['multi_crew_auto_cancel_enabled'] ?? true));
        $settings->set('multi_crew_auto_cancel_minutes', max(1, min(180, (int) ($data['multi_crew_auto_cancel_minutes'] ?? 7))));
        $settings->set('multi_crew_auto_cancel_message', $data['multi_crew_auto_cancel_message'] ?? 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.');
        $settings->set('driver_daily_priority_enabled', (bool) ($data['driver_daily_priority_enabled'] ?? true));
        $settings->set('driver_daily_priority_hold_minutes', max(1, min(60, (int) ($data['driver_daily_priority_hold_minutes'] ?? 3))));
        $settings->set('driver_daily_priority_windows', json_encode($this->normalizeDailyPriorityWindows($data['driver_daily_priority_windows'] ?? [])));
        $settings->set('night_tariff_enabled', (bool) ($data['night_tariff_enabled'] ?? true));
        $settings->set('night_tariff_rules', json_encode($this->normalizeNightTariffRules($data['night_tariff_rules'] ?? [])));
        $settings->set('assign_driver_allowed_roles', json_encode($this->normalizeAssignDriverRoles($data['assign_driver_allowed_roles'] ?? self::DEFAULT_ASSIGN_DRIVER_ROLES)));
        $settings->set('payment_methods', json_encode($this->paymentMethods($data)));
        $settings->set('payment_transfer_account', json_encode($this->normalizeTransferAccounts($data['payment_bank_accounts'] ?? [])));
        $settings->set('payment_qris_image', $this->normalizeUploadState($data['qris_image'] ?? null));
        $settings->set('complaint_whatsapp_number', $this->normalizeWhatsappNumber((string) ($data['complaint_whatsapp_number'] ?? '6281299232918')));

        if (auth()->user()?->role === UserRole::Admin) {
            if (filled($data['fcm_service_account_json'] ?? null) && ! $this->isValidServiceAccountJson((string) $data['fcm_service_account_json'])) {
                Notification::make()
                    ->title('Service Account JSON tidak valid')
                    ->body('Paste isi file JSON penuh dari Firebase, bukan hanya email service account.')
                    ->danger()
                    ->send();

                return;
            }

            if (filled($data['firebase_web_config'] ?? null) && ! $this->isValidFirebaseWebConfig((string) $data['firebase_web_config'])) {
                Notification::make()
                    ->title('Firebase Web Config tidak valid')
                    ->body('Paste object firebaseConfig dari Firebase Console atau JSON yang berisi apiKey, projectId, messagingSenderId, dan appId.')
                    ->danger()
                    ->send();

                return;
            }

            $this->saveSecret($settings, 'google_maps_api_key', $data['google_maps_api_key'] ?? null, (bool) ($data['google_maps_active'] ?? false));
            $this->saveSecret($settings, 'mapbox_api_key', $data['mapbox_api_key'] ?? null, (bool) ($data['mapbox_active'] ?? false));
            $this->saveSecret($settings, 'fcm_server_key', $data['fcm_server_key'] ?? null, (bool) ($data['fcm_active'] ?? false));
            $this->saveSecret($settings, 'fcm_service_account_json', $data['fcm_service_account_json'] ?? null, (bool) ($data['fcm_service_account_active'] ?? false));
            $this->saveSecret($settings, 'google_oauth_secret', $data['google_oauth_secret'] ?? null, (bool) ($data['google_oauth_enabled'] ?? false));
            $this->saveSecret($settings, 'openai_api_key', $data['openai_api_key'] ?? null, (bool) ($data['openai_active'] ?? false));
            $this->saveSecret($settings, 'kimi_api_key', $data['kimi_api_key'] ?? null, (bool) ($data['kimi_active'] ?? false));
            $this->saveSecret($settings, 'blackbox_api_key', $data['blackbox_api_key'] ?? null, (bool) ($data['blackbox_active'] ?? false));
            $this->saveSecret($settings, 'openrouter_api_key', $data['openrouter_api_key'] ?? null, (bool) ($data['openrouter_active'] ?? false));
            $this->saveSecret($settings, 'hermes_api_key', $data['hermes_api_key'] ?? null, (bool) ($data['hermes_active'] ?? false));

            $settings->set('map_provider', $data['map_provider'] ?? 'osm', (bool) ($data['map_active'] ?? true));
            $normalLocationLogRetention = max(1, min(365, (int) ($data['location_log_retention_days'] ?? 14)));
            $settings->set('location_log_cleanup_enabled', (bool) ($data['location_log_cleanup_enabled'] ?? true));
            $settings->set('location_log_retention_days', $normalLocationLogRetention);
            $settings->set('location_log_suspicious_retention_days', max($normalLocationLogRetention, min(730, (int) ($data['location_log_suspicious_retention_days'] ?? 30))));
            $settings->set('location_log_cleanup_batch_limit', max(100, min(10000, (int) ($data['location_log_cleanup_batch_limit'] ?? 1000))));
            $settings->set('google_oauth_enabled', (bool) ($data['google_oauth_enabled'] ?? false));
            $settings->set('google_oauth_client_id', $data['google_oauth_client_id'] ?? null, (bool) ($data['google_oauth_enabled'] ?? false));
            $settings->set('firebase_web_config', $data['firebase_web_config'] ?? null, (bool) ($data['firebase_web_config_active'] ?? false));
            $settings->set('firebase_vapid_key', $data['firebase_vapid_key'] ?? null, (bool) ($data['firebase_vapid_active'] ?? false));
            $settings->set('ai_assistant_enabled', (bool) ($data['ai_assistant_enabled'] ?? false));
            $settings->set('ai_provider', $data['ai_provider'] ?? 'openai');
            $settings->set('ai_model', filled($data['ai_model_custom'] ?? null) ? $data['ai_model_custom'] : ($data['ai_model'] ?? null));
            $settings->set('ai_base_url', $data['ai_base_url'] ?? null);
            $settings->set('ai_max_tokens', max(200, min(1500, (int) ($data['ai_max_tokens'] ?? 700))));
            $settings->set('hermes_enabled', (bool) ($data['hermes_enabled'] ?? false));
            $settings->set('hermes_provider', $data['hermes_provider'] ?? 'openai_compatible');
            $settings->set('hermes_model', $data['hermes_model'] ?? 'nousresearch/hermes-3-llama-3.1-405b');
            $settings->set('hermes_base_url', $data['hermes_base_url'] ?? null);
            $settings->set('hermes_max_tokens', max(600, min(4000, (int) ($data['hermes_max_tokens'] ?? 1800))));
        }
        $settings->applyToConfig();

        Notification::make()
            ->title('App settings tersimpan')
            ->body('Cache settings sudah dibersihkan dan konfigurasi runtime diperbarui.')
            ->success()
            ->send();

        $this->mount($settings);
    }

    private function saveSecret(SettingService $settings, string $key, ?string $value, bool $active): void
    {
        if (filled($value)) {
            $settings->set($key, $value, $active);

            return;
        }

        $current = $settings->raw($key);
        if ($current) {
            $current->forceFill(['is_active' => $active])->save();
        }
    }

    private function aiModelOptions(string $provider): array
    {
        return match ($provider) {
            'openrouter' => [
                'openrouter/free' => 'OpenRouter Free Router - otomatis pilih model gratis tersedia',
            ],
            'kimi' => [
                'kimi-pro' => 'Kimi Pro',
                'moonshot-v1-8k' => 'Moonshot v1 8K',
            ],
            'blackbox' => [
                'blackboxai/openai/gpt-4o-mini' => 'Blackbox GPT-4o Mini',
            ],
            default => [
                'gpt-4o-mini' => 'OpenAI GPT-4o Mini',
                'gpt-4.1-mini' => 'OpenAI GPT-4.1 Mini',
            ],
        };
    }

    private function isValidServiceAccountJson(string $value): bool
    {
        $decoded = json_decode($value, true);

        return is_array($decoded)
            && filled($decoded['project_id'] ?? null)
            && filled($decoded['client_email'] ?? null)
            && filled($decoded['private_key'] ?? null);
    }

    private function isValidFirebaseWebConfig(string $value): bool
    {
        $decoded = json_decode($value, true);

        if (! is_array($decoded) && preg_match('/\{.*\}/s', $value, $match) === 1) {
            $jsonLike = preg_replace('/\/\/.*$/m', '', $match[0]);
            $jsonLike = preg_replace('/([,{]\s*)([A-Za-z_$][A-Za-z0-9_$]*)\s*:/', '$1"$2":', (string) $jsonLike);
            $jsonLike = preg_replace('/,\s*}/', '}', (string) $jsonLike);
            $decoded = json_decode((string) $jsonLike, true);
        }

        return is_array($decoded)
            && filled($decoded['apiKey'] ?? null)
            && filled($decoded['projectId'] ?? null)
            && filled($decoded['messagingSenderId'] ?? null)
            && filled($decoded['appId'] ?? null);
    }

    private function nightTariffRules(SettingService $settings): array
    {
        $raw = $settings->get('night_tariff_rules');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (is_array($decoded) && $decoded !== []) {
            return $this->normalizeNightTariffRules($decoded);
        }

        return $this->defaultNightTariffRules();
    }

    private function assignDriverAllowedRoles(SettingService $settings): array
    {
        $raw = $settings->get('assign_driver_allowed_roles', json_encode(self::DEFAULT_ASSIGN_DRIVER_ROLES));
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeAssignDriverRoles(is_array($decoded) ? $decoded : self::DEFAULT_ASSIGN_DRIVER_ROLES);
    }

    private function dailyPriorityWindows(SettingService $settings): array
    {
        $raw = $settings->get('driver_daily_priority_windows');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return $this->normalizeDailyPriorityWindows(is_array($decoded) ? $decoded : []);
    }

    private function normalizeAssignDriverRoles(array $roles): array
    {
        $allowed = ['manager', 'spv', 'operator', 'eksekutor'];

        return collect($roles)
            ->map(fn (mixed $role): string => strtolower(trim((string) $role)))
            ->filter(fn (string $role): bool => in_array($role, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function transferAccounts(SettingService $settings): array
    {
        $raw = $settings->get('payment_transfer_account');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        if (! is_array($decoded)) {
            return [['bank' => '', 'account_name' => '', 'account_number' => '']];
        }

        if (array_is_list($decoded)) {
            return $this->normalizeTransferAccounts($decoded);
        }

        return $this->normalizeTransferAccounts([$decoded]);
    }

    private function normalizeTransferAccounts(array $accounts): array
    {
        $normalized = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $bank = trim((string) ($account['bank'] ?? ''));
            $name = trim((string) ($account['account_name'] ?? ''));
            $number = trim((string) ($account['account_number'] ?? ''));

            if ($bank === '' && $name === '' && $number === '') {
                continue;
            }

            $normalized[] = [
                'bank' => $bank,
                'account_name' => $name,
                'account_number' => $number,
            ];
        }

        return $normalized;
    }

    private function normalizeUploadState(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)
                ->flatten()
                ->filter(fn (mixed $item): bool => filled($item))
                ->first();
        }

        if (! filled($value)) {
            return null;
        }

        $path = (string) $value;
        $path = preg_replace('#^https?://[^/]+/storage/#i', '', $path) ?? $path;
        $path = preg_replace('#^/?storage/#i', '', $path) ?? $path;

        return ltrim($path, '/');
    }

    private function paymentMethods(array $data): array
    {
        $methods = [];

        if ((bool) ($data['payment_cash_enabled'] ?? true)) {
            $methods[] = ['key' => 'cash', 'label' => 'Pembayaran Cash', 'description' => 'Customer membayar manual kepada driver.'];
        }

        if ((bool) ($data['payment_transfer_enabled'] ?? true)) {
            $methods[] = ['key' => 'transfer', 'label' => 'Pembayaran Transfer', 'description' => 'Customer transfer ke rekening aplikasi.'];
        }

        return $methods === [] ? [['key' => 'cash', 'label' => 'Pembayaran Cash', 'description' => 'Customer membayar manual kepada driver.']] : $methods;
    }

    private function normalizeWhatsappNumber(string $number): string
    {
        $number = preg_replace('/\D+/', '', $number) ?: '6281299232918';

        if (str_starts_with($number, '0')) {
            return '62'.substr($number, 1);
        }

        return $number;
    }

    private function defaultNightTariffRules(): array
    {
        return [
            ['area' => 'bws', 'start' => '21:30', 'end' => '00:00', 'percent' => 30],
            ['area' => 'bondowoso', 'start' => '21:30', 'end' => '00:00', 'percent' => 30],
            ['area' => '', 'start' => '22:00', 'end' => '00:00', 'percent' => 30],
            ['area' => '', 'start' => '00:01', 'end' => '04:00', 'percent' => 50],
            ['area' => '', 'start' => '04:01', 'end' => '06:00', 'percent' => 30],
        ];
    }

    private function defaultDailyPriorityWindows(): array
    {
        return [
            ['start' => '05:00', 'end' => '11:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ];
    }

    private function normalizeDailyPriorityWindows(array $windows): array
    {
        $normalized = [];

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $start = $this->normalizeTime((string) ($window['start'] ?? '05:00'));
            $end = $this->normalizeTime((string) ($window['end'] ?? '11:00'));

            if ($start === $end) {
                continue;
            }

            $normalized[] = ['start' => $start, 'end' => $end];
        }

        return $normalized === [] ? $this->defaultDailyPriorityWindows() : $normalized;
    }

    private function normalizeNightTariffRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $percent = max(0, min(200, (int) ($rule['percent'] ?? 0)));

            if ($percent <= 0) {
                continue;
            }

            $normalized[] = [
                'area' => strtolower(trim((string) ($rule['area'] ?? ''))),
                'start' => $this->normalizeTime((string) ($rule['start'] ?? '00:00')),
                'end' => $this->normalizeTime((string) ($rule['end'] ?? '00:00')),
                'percent' => $percent,
            ];
        }

        return $normalized === [] ? $this->defaultNightTariffRules() : $normalized;
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', trim($time), $match) !== 1) {
            return '00:00';
        }

        return sprintf('%02d:%02d', min(23, max(0, (int) $match[1])), min(59, max(0, (int) $match[2])));
    }
}
