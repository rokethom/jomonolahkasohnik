<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hermes_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('command', 80);
            $table->text('instruction')->nullable();
            $table->string('model', 160)->nullable();
            $table->string('status', 30)->default('completed');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('context_summary')->nullable();
            $table->longText('report')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['command', 'status']);
            $table->index('created_at');
        });

        $defaults = [
            ['key' => 'hermes_enabled', 'value' => 'false', 'is_active' => true, 'meta' => null],
            ['key' => 'hermes_provider', 'value' => 'openai_compatible', 'is_active' => true, 'meta' => null],
            ['key' => 'hermes_model', 'value' => 'nousresearch/hermes-3-llama-3.1-405b', 'is_active' => true, 'meta' => null],
            ['key' => 'hermes_base_url', 'value' => null, 'is_active' => true, 'meta' => null],
            ['key' => 'hermes_api_key', 'value' => null, 'is_active' => false, 'meta' => json_encode(['encrypted' => true])],
            ['key' => 'hermes_max_tokens', 'value' => '1800', 'is_active' => true, 'meta' => null],
        ];

        foreach ($defaults as $setting) {
            AppSetting::query()->firstOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'is_active' => $setting['is_active'],
                    'meta' => $setting['meta'] ? json_decode($setting['meta'], true) : null,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hermes_reports');

        AppSetting::query()
            ->whereIn('key', [
                'hermes_enabled',
                'hermes_provider',
                'hermes_model',
                'hermes_base_url',
                'hermes_api_key',
                'hermes_max_tokens',
            ])
            ->delete();
    }
};
