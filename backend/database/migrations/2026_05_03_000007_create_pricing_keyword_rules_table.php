<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_keyword_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('keywords');
            $table->integer('amount')->default(0);
            $table->json('service_scopes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_keyword_rules');
    }
};
