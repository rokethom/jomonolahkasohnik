<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach ($this->defaults() as $setting) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [...$setting, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }

    private function defaults(): array
    {
        return [
            ['key' => 'map_provider', 'value' => 'osm', 'is_active' => true, 'meta' => json_encode(['label' => 'OpenStreetMap'])],
            ['key' => 'google_maps_api_key', 'value' => null, 'is_active' => false, 'meta' => null],
            ['key' => 'mapbox_api_key', 'value' => null, 'is_active' => false, 'meta' => null],
            ['key' => 'google_oauth_enabled', 'value' => 'false', 'is_active' => true, 'meta' => null],
            ['key' => 'google_oauth_client_id', 'value' => null, 'is_active' => false, 'meta' => null],
            ['key' => 'google_oauth_secret', 'value' => null, 'is_active' => false, 'meta' => null],
            ['key' => 'fcm_server_key', 'value' => null, 'is_active' => false, 'meta' => null],
        ];
    }
};
