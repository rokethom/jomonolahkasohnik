<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $secretKeys = [
        'google_maps_api_key',
        'mapbox_api_key',
        'fcm_server_key',
        'google_oauth_secret',
    ];

    public function up(): void
    {
        DB::table('app_settings')
            ->whereIn('key', $this->secretKeys)
            ->whereNotNull('value')
            ->orderBy('id')
            ->each(function ($setting): void {
                $meta = json_decode($setting->meta ?? '[]', true) ?: [];

                if ($meta['encrypted'] ?? false) {
                    return;
                }

                $meta['encrypted'] = true;

                DB::table('app_settings')
                    ->where('id', $setting->id)
                    ->update([
                        'value' => Crypt::encryptString($setting->value),
                        'meta' => json_encode($meta),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->whereIn('key', $this->secretKeys)
            ->whereNotNull('value')
            ->orderBy('id')
            ->each(function ($setting): void {
                $meta = json_decode($setting->meta ?? '[]', true) ?: [];

                if (! ($meta['encrypted'] ?? false)) {
                    return;
                }

                unset($meta['encrypted']);

                DB::table('app_settings')
                    ->where('id', $setting->id)
                    ->update([
                        'value' => Crypt::decryptString($setting->value),
                        'meta' => $meta === [] ? null : json_encode($meta),
                        'updated_at' => now(),
                    ]);
            });
    }
};
