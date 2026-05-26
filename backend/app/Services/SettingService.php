<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class SettingService
{
    private const CACHE_KEY = 'app_settings.all';

    private const SECRET_KEYS = [
        'google_maps_api_key',
        'mapbox_api_key',
        'fcm_server_key',
        'fcm_service_account_json',
        'google_oauth_secret',
        'openai_api_key',
        'kimi_api_key',
        'blackbox_api_key',
        'openrouter_api_key',
    ];

    private const ENV_FALLBACKS = [
        'google_maps_api_key' => 'GOOGLE_MAPS_API_KEY',
        'mapbox_api_key' => 'MAPBOX_API_KEY',
        'fcm_server_key' => 'FCM_SERVER_KEY',
        'fcm_service_account_json' => 'FCM_SERVICE_ACCOUNT_JSON',
        'firebase_vapid_key' => 'FIREBASE_VAPID_KEY',
        'firebase_web_config' => 'FIREBASE_WEB_CONFIG',
        'map_provider' => 'MAP_PROVIDER',
        'google_maps_distance_enabled' => 'GOOGLE_MAPS_DISTANCE_ENABLED',
        'google_maps_geocode_enabled' => 'GOOGLE_MAPS_GEOCODE_ENABLED',
        'osrm_base_url' => 'OSRM_BASE_URL',
        'osrm_active' => 'OSRM_ACTIVE',
        'google_oauth_enabled' => 'GOOGLE_OAUTH_ENABLED',
        'google_oauth_client_id' => 'GOOGLE_OAUTH_CLIENT_ID',
        'google_oauth_secret' => 'GOOGLE_OAUTH_SECRET',
        'multi_order_enabled' => 'MULTI_ORDER_ENABLED',
        'max_multi_order' => 'MAX_MULTI_ORDER',
        'ai_assistant_enabled' => 'AI_ASSISTANT_ENABLED',
        'ai_provider' => 'AI_PROVIDER',
        'ai_model' => 'AI_MODEL',
        'ai_base_url' => 'AI_BASE_URL',
        'ai_max_tokens' => 'AI_MAX_TOKENS',
        'openai_api_key' => 'OPENAI_API_KEY',
        'kimi_api_key' => 'KIMI_API_KEY',
        'blackbox_api_key' => 'BLACKBOX_API_KEY',
        'openrouter_api_key' => 'OPENROUTER_API_KEY',
    ];

    public function all(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => AppSetting::query()
            ->get()
            ->keyBy('key'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $setting = $this->all()->get($key);

        if (! $setting?->is_active) {
            return $this->fallback($key, $default);
        }

        if ($setting->value === null) {
            return $this->fallback($key, $default);
        }

        return $this->decodeValue($setting->value, $setting->meta ?? []);
    }

    public function raw(string $key): ?AppSetting
    {
        return $this->all()->get($key);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    public function set(string $key, mixed $value, bool $isActive = true, ?array $meta = null): AppSetting
    {
        $setting = AppSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $this->encodeValue($key, $value),
                'is_active' => $isActive,
                'meta' => $this->metaFor($key, $meta),
            ],
        );

        $this->clearCache();

        return $setting;
    }

    public function clearCache(): bool
    {
        return Cache::forget(self::CACHE_KEY);
    }

    public function getMapProvider(): array
    {
        $configuredProvider = $this->normalizeMapProvider($this->get('map_provider', 'osm'));

        if ($configuredProvider === 'google') {
            $apiKey = $this->get('google_maps_api_key');
            if (filled($apiKey)) {
                return $this->mapProviderResponse('google', $apiKey, $configuredProvider);
            }

            Log::warning('Map provider fallback to OSM: Google selected without active API key');
        }

        if ($configuredProvider === 'mapbox') {
            $apiKey = $this->get('mapbox_api_key');
            if (filled($apiKey)) {
                return $this->mapProviderResponse('mapbox', $apiKey, $configuredProvider);
            }

            Log::warning('Map provider fallback to OSM: Mapbox selected without active API key');
        }

        return $this->mapProviderResponse('osm', null, $configuredProvider);
    }

    public function publicSettings(): array
    {
        return [
            'branding' => [
                'bot_display_name' => $this->botDisplayName(),
                'customer' => $this->brandingAssets('customer'),
                'driver' => $this->brandingAssets('driver'),
                'admin' => $this->brandingAssets('admin'),
            ],
            'map' => $this->getMapProvider(),
            'oauth' => [
                'google_enabled' => $this->bool('google_oauth_enabled')
                    && filled($this->get('google_oauth_client_id'))
                    && filled($this->get('google_oauth_secret')),
                'google_client_id' => env('GOOGLE_DRIVER_CLIENT_ID') ?: $this->get('google_oauth_client_id'),
            ],
            'push' => [
                'enabled' => filled($this->get('firebase_vapid_key')) && filled($this->firebaseWebConfig()),
                'vapid_key' => $this->get('firebase_vapid_key'),
                'firebase_config' => $this->firebaseWebConfig(),
            ],
            'payment' => [
                'methods' => $this->paymentMethods(),
                'transfer_accounts' => $this->transferAccounts(),
                'transfer_account' => $this->transferAccounts()[0] ?? ['bank' => '', 'account_name' => '', 'account_number' => ''],
                'qris_image_url' => $this->publicStorageUrl($this->get('payment_qris_image')),
            ],
            'support' => [
                'complaint_whatsapp_number' => $this->get('complaint_whatsapp_number', '6281299232918'),
                'complaint_whatsapp_url' => 'https://wa.me/'.$this->normalizeWhatsappNumber((string) $this->get('complaint_whatsapp_number', '6281299232918')),
            ],
        ];
    }

    public function brandingAssets(string $surface): array
    {
        $keys = match ($surface) {
            'customer' => ['logo', 'hero_image', 'favicon', 'apple_touch_icon', 'pwa_icon_192', 'pwa_icon_512'],
            'driver' => ['logo', 'favicon', 'apple_touch_icon', 'pwa_icon_192', 'pwa_icon_512'],
            'admin' => ['logo', 'favicon', 'pwa_icon_192', 'pwa_icon_512'],
            default => [],
        };

        $assets = ['app_name' => $this->brandingAppName($surface)];
        foreach ($keys as $key) {
            $assets[$key.'_url'] = $this->publicStorageUrl($this->get("branding_{$surface}_{$key}"));
        }

        return $assets;
    }

    public function brandingAppName(string $surface): string
    {
        $default = match ($surface) {
            'driver' => 'Driver Joker',
            'admin' => 'Admin Joker',
            default => 'Joker',
        };
        $name = trim((string) $this->get("branding_{$surface}_app_name", $default));

        return $name !== '' ? $name : $default;
    }

    public function brandingBackendName(): string
    {
        try {
            $name = trim((string) $this->get('branding_backend_app_name', 'Jojoapp'));
        } catch (Throwable) {
            return 'Jojoapp';
        }

        return $name !== '' ? $name : 'Jojoapp';
    }

    public function pwaManifest(string $surface, string $origin): array
    {
        $assets = $this->brandingAssets($surface);
        $appName = $this->brandingAppName($surface);
        $defaults = match ($surface) {
            'driver' => [
                'name' => 'Driver Joker',
                'short_name' => 'Driver Joker',
                'description' => 'Driver Jojo si Aplikasi Joker',
                'background_color' => '#07182f',
                'theme_color' => '#07182f',
                'categories' => ['business', 'productivity', 'navigation'],
                'icon_192' => '/jojo_driver_192.png',
                'icon_512' => '/jojo_driver_512.png',
            ],
            'admin' => [
                'name' => 'Admin Joker',
                'short_name' => 'Admin Joker',
                'description' => 'Admin dashboard Jojo si Aplikasi Joker',
                'background_color' => '#111827',
                'theme_color' => '#111827',
                'categories' => ['business', 'productivity'],
                'icon_192' => '/jojo_admin_192.png',
                'icon_512' => '/logo.png',
            ],
            default => [
                'name' => 'JOJO si Aplikasi Joker',
                'short_name' => 'Joker',
                'description' => 'Aplikasi Joker.',
                'background_color' => '#ffffff',
                'theme_color' => '#00b7ff',
                'categories' => ['lifestyle', 'shopping', 'utilities'],
                'icon_192' => '/jojo192.png',
                'icon_512' => '/jojo512.png',
            ],
        };

        $icon192 = $assets['pwa_icon_192_url'] ?: $origin.$defaults['icon_192'];
        $icon512 = $assets['pwa_icon_512_url'] ?: $origin.$defaults['icon_512'];

        return [
            'name' => $appName,
            'short_name' => $appName,
            'description' => $defaults['description'],
            'id' => '/',
            'start_url' => $origin.'/?source=pwa',
            'scope' => $origin.'/',
            'display' => 'standalone',
            'display_override' => ['standalone', 'minimal-ui'],
            'orientation' => 'portrait',
            'background_color' => $defaults['background_color'],
            'theme_color' => $defaults['theme_color'],
            'categories' => $defaults['categories'],
            'icons' => [
                ['src' => $icon192, 'sizes' => '192x192', 'type' => $this->imageMimeType($icon192), 'purpose' => 'any'],
                ['src' => $icon512, 'sizes' => '512x512', 'type' => $this->imageMimeType($icon512), 'purpose' => 'any'],
                ['src' => $icon192, 'sizes' => '192x192', 'type' => $this->imageMimeType($icon192), 'purpose' => 'maskable'],
                ['src' => $icon512, 'sizes' => '512x512', 'type' => $this->imageMimeType($icon512), 'purpose' => 'maskable'],
            ],
        ];
    }

    public function botDisplayName(): string
    {
        $name = trim((string) $this->get('bot_display_name', 'Joana'));

        return $name !== '' ? $name : 'Joana';
    }

    public function replaceBotName(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('/\bJOJOBOT\b/i', $this->botDisplayName(), $value) ?? $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->replaceBotName($item);
        }

        return $value;
    }

    private function paymentMethods(): array
    {
        $methods = $this->jsonSetting('payment_methods', [
            ['key' => 'cash', 'label' => 'Pembayaran Cash', 'description' => 'Customer membayar manual kepada driver.'],
            ['key' => 'transfer', 'label' => 'Pembayaran Transfer', 'description' => 'Customer transfer ke rekening aplikasi.'],
        ]);

        if ($this->publicStorageUrl($this->get('payment_qris_image')) && ! collect($methods)->firstWhere('key', 'qris')) {
            $methods[] = ['key' => 'qris', 'label' => 'Pembayaran QRIS', 'description' => 'Customer scan QRIS aplikasi.'];
        }

        return $methods;
    }

    public function applyToConfig(): void
    {
        config([
            'services.google.maps_key' => $this->get('google_maps_api_key'),
            'services.mapbox.key' => $this->get('mapbox_api_key'),
            'services.fcm.server_key' => $this->get('fcm_server_key'),
            'services.google.client_id' => $this->get('google_oauth_client_id'),
            'services.google.client_secret' => $this->get('google_oauth_secret'),
            'services.google.redirect' => config('services.google.redirect') ?: url('/api/auth/google/callback'),
            'jojo.map' => $this->getMapProvider(),
            'jojo.oauth.google_enabled' => $this->bool('google_oauth_enabled'),
            'jojo.ai.enabled' => $this->bool('ai_assistant_enabled', false),
            'jojo.ai.provider' => $this->get('ai_provider', 'openrouter'),
            'jojo.ai.model' => $this->get('ai_model'),
            'jojo.ai.base_url' => $this->get('ai_base_url'),
            'jojo.ai.max_tokens' => $this->int('ai_max_tokens', 700),
        ]);
    }

    public function mask(?string $value): string
    {
        if (! filled($value)) {
            return 'Belum diisi';
        }

        $visible = str($value)->substr(-4)->toString();

        return '****'.$visible;
    }

    public function firebaseWebConfig(): ?array
    {
        $config = $this->get('firebase_web_config');
        if (! filled($config)) {
            return null;
        }

        if (is_array($config)) {
            return $config;
        }

        $config = trim((string) $config);
        $decoded = json_decode($config, true);

        if (! is_array($decoded) && preg_match('/\{.*\}/s', $config, $match) === 1) {
            $jsonLike = preg_replace('/\/\/.*$/m', '', $match[0]);
            $jsonLike = preg_replace('/([,{]\s*)([A-Za-z_$][A-Za-z0-9_$]*)\s*:/', '$1"$2":', $jsonLike);
            $jsonLike = preg_replace('/,\s*}/', '}', (string) $jsonLike);
            $decoded = json_decode((string) $jsonLike, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeMapProvider(mixed $provider): string
    {
        $provider = str((string) $provider)->lower()->trim()->toString();

        return match ($provider) {
            'google', 'google_maps', 'google-maps' => 'google',
            'mapbox', 'map_box', 'map-box' => 'mapbox',
            'openstreetmap', 'open_street_map', 'open-street-map', 'leaflet', 'osm' => 'osm',
            default => 'osm',
        };
    }

    private function mapProviderResponse(string $provider, ?string $apiKey, string $configuredProvider): array
    {
        return [
            'provider' => $provider,
            'api_key' => $apiKey,
            'configured_provider' => $configuredProvider,
            'fallback' => $provider !== $configuredProvider,
        ];
    }

    private function jsonSetting(string $key, array $default): array
    {
        $value = $this->get($key);

        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : $default;
    }

    private function transferAccounts(): array
    {
        $raw = $this->get('payment_transfer_account');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : $raw;

        if (! is_array($decoded)) {
            return [];
        }

        $accounts = array_is_list($decoded) ? $decoded : [$decoded];

        return collect($accounts)
            ->filter(fn (mixed $account): bool => is_array($account))
            ->map(fn (array $account): array => [
                'bank' => trim((string) ($account['bank'] ?? '')),
                'account_name' => trim((string) ($account['account_name'] ?? '')),
                'account_number' => trim((string) ($account['account_number'] ?? '')),
            ])
            ->filter(fn (array $account): bool => $account['bank'] !== '' || $account['account_name'] !== '' || $account['account_number'] !== '')
            ->values()
            ->all();
    }

    private function publicStorageUrl(mixed $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (is_array($path)) {
            $path = collect($path)->flatten()->filter(fn (mixed $item): bool => filled($item))->first();
        }

        if (! filled($path)) {
            return null;
        }

        $path = (string) $path;
        $path = preg_replace('#^https?://[^/]+/storage/#i', '', $path) ?? $path;
        $path = preg_replace('#^/?storage/#i', '', $path) ?? $path;
        $path = ltrim($path, '/');

        return str_starts_with($path, 'http') ? $path : url('/api/media/'.$path);
    }

    private function imageMimeType(string $path): string
    {
        $extension = strtolower((string) pathinfo((string) parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($extension) {
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };
    }

    private function normalizeWhatsappNumber(string $number): string
    {
        $number = preg_replace('/\D+/', '', $number) ?: '6281299232918';

        if (str_starts_with($number, '0')) {
            return '62'.substr($number, 1);
        }

        return $number;
    }

    private function fallback(string $key, mixed $default): mixed
    {
        $envKey = self::ENV_FALLBACKS[$key] ?? null;

        return $envKey ? env($envKey, $default) : $default;
    }

    private function encodeValue(string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $this->isSecret($key) ? Crypt::encryptString($value) : $value;
    }

    private function decodeValue(string $value, array $meta): string
    {
        if (! ($meta['encrypted'] ?? false)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable $exception) {
            Log::warning('Failed decrypting app setting value', ['message' => $exception->getMessage()]);

            return '';
        }
    }

    private function metaFor(string $key, ?array $meta): ?array
    {
        $meta ??= [];

        if ($this->isSecret($key)) {
            $meta['encrypted'] = true;
        }

        return $meta === [] ? null : $meta;
    }

    private function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }
}
