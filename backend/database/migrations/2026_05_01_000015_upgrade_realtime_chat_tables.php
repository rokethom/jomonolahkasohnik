<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->string('type')->default('customer_operator')->after('order_id')->index();
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->timestamp('last_customer_message_at')->nullable()->after('closed_at');
            $table->timestamp('first_operator_response_at')->nullable()->after('last_customer_message_at');
            $table->string('sla_status')->nullable()->after('first_operator_response_at')->index();
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->string('sender_type')->default('user')->after('sender_id');
            $table->string('image_url')->nullable()->after('message');
            $table->string('audio_url')->nullable()->after('image_url');
            $table->unsignedInteger('audio_duration')->nullable()->after('audio_url');
            $table->boolean('is_read')->default(false)->after('audio_duration');
        });

        Schema::create('cancel_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('image_url')->nullable();
            $table->string('status')->default('pending')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sla_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('first_response_time')->nullable();
            $table->string('sla_status')->default('waiting')->index();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_logs');
        Schema::dropIfExists('cancel_requests');

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropColumn(['sender_type', 'image_url', 'audio_url', 'audio_duration', 'is_read']);
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropColumn(['type', 'closed_at', 'last_customer_message_at', 'first_operator_response_at', 'sla_status']);
        });
    }
};
