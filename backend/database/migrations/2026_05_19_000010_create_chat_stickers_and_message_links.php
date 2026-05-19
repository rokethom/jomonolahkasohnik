<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_stickers')) {
            Schema::create('chat_stickers', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('category')->default('umum')->index();
                $table->string('image_path');
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        Schema::table('chat_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_messages', 'chat_sticker_id')) {
                $table->foreignId('chat_sticker_id')->nullable()->after('file_size')->constrained('chat_stickers')->nullOnDelete();
            }

            if (! Schema::hasColumn('chat_messages', 'message_type')) {
                $table->string('message_type')->default('text')->after('chat_sticker_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('chat_messages', 'chat_sticker_id')) {
                $table->dropConstrainedForeignId('chat_sticker_id');
            }

            if (Schema::hasColumn('chat_messages', 'message_type')) {
                $table->dropColumn('message_type');
            }
        });

        Schema::dropIfExists('chat_stickers');
    }
};
