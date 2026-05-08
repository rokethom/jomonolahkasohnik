<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_chat_rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->default('branch')->index();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('internal_chat_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['internal_chat_room_id', 'user_id'], 'internal_chat_participant_unique');
        });

        Schema::create('internal_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_chat_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_chat_messages');
        Schema::dropIfExists('internal_chat_participants');
        Schema::dropIfExists('internal_chat_rooms');
    }
};
