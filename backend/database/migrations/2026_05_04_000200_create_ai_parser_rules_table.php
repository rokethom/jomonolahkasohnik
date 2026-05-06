<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_parser_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('text_hash', 64)->unique();
            $table->text('normalized_text');
            $table->string('service_type', 30)->index();
            $table->json('ai_data');
            $table->text('example_text');
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_parser_rules');
    }
};
