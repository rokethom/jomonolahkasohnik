<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_parsers', function (Blueprint $table): void {
            $table->id();
            $table->string('keyword')->unique();
            $table->string('service_type', 20)->index();
            $table->text('response_template');
            $table->string('parser_type', 20)->default('simple')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('priority')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_parsers');
    }
};
