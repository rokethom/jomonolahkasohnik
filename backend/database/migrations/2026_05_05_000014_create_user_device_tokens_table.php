<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('platform', 40)->default('web');
            $table->string('app', 40)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'firebase_vapid_key'],
            ['value' => null, 'is_active' => false, 'meta' => null, 'created_at' => now(), 'updated_at' => now()],
        );

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'firebase_web_config'],
            ['value' => null, 'is_active' => false, 'meta' => null, 'created_at' => now(), 'updated_at' => now()],
        );

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'fcm_service_account_json'],
            ['value' => null, 'is_active' => false, 'meta' => json_encode(['encrypted' => true]), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_device_tokens');

        DB::table('app_settings')->whereIn('key', [
            'firebase_vapid_key',
            'firebase_web_config',
            'fcm_service_account_json',
        ])->delete();
    }
};
