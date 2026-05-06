<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('image')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('order')->default(0)->index();
            $table->dateTime('start_date')->nullable()->index();
            $table->dateTime('end_date')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('home_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('grid');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('home_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('home_sections')->cascadeOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('image')->nullable();
            $table->string('icon')->nullable();
            $table->string('link')->nullable();
            $table->json('extra_data')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('start_date')->nullable()->index();
            $table->dateTime('end_date')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('home_items');
        Schema::dropIfExists('home_sections');
        Schema::dropIfExists('banners');
    }
};
