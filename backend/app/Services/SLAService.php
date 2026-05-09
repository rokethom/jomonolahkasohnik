<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SlaLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class SLAService
{
    public function thresholdSeconds(): int
    {
        return (int) config('chat.sla_seconds', 300);
    }

    public function feedbackSeconds(): int
    {
        return (int) config('chat.feedback_seconds', 600);
    }

    public function autoCloseSeconds(): int
    {
        return (int) config('chat.auto_close_seconds', 900);
    }

    public function start(ChatConversation $conversation, ChatMessage $message): void
    {
        if (! in_array($conversation->type, ['customer_operator', 'driver_operator'], true)) {
            return;
        }

        $conversation->forceFill([
            'status' => 'waiting',
            'last_customer_message_at' => now(),
            'first_operator_response_at' => null,
            'sla_status' => 'waiting',
        ])->save();

        SlaLog::create([
            'chat_conversation_id' => $conversation->id,
            'chat_message_id' => $message->id,
            'sla_status' => 'waiting',
            'due_at' => now()->addSeconds($this->thresholdSeconds()),
        ]);
    }

    public function resolve(ChatConversation $conversation): void
    {
        if (! $conversation->last_customer_message_at || $conversation->first_operator_response_at) {
            return;
        }

        $seconds = $conversation->last_customer_message_at->diffInSeconds(now());
        $status = $seconds <= $this->thresholdSeconds() ? 'on_time' : 'late';

        $conversation->forceFill([
            'status' => $conversation->status === 'waiting' ? 'active' : $conversation->status,
            'first_operator_response_at' => now(),
            'sla_status' => $status,
        ])->save();

        $conversation->slaLogs()
            ->where('sla_status', 'waiting')
            ->latest()
            ->first()
            ?->update([
                'first_response_time' => $seconds,
                'sla_status' => $status,
            ]);
    }

    public function enforceUnansweredOperatorChat(ChatConversation $conversation): ChatConversation
    {
        if (
            $conversation->type !== 'customer_operator'
            || $conversation->status === 'closed'
            || ! $conversation->last_customer_message_at
            || $conversation->first_operator_response_at
        ) {
            return $conversation;
        }

        $seconds = $conversation->last_customer_message_at->diffInSeconds(now());
        $shouldRequestFeedback = $seconds >= $this->feedbackSeconds();
        $shouldClose = $seconds >= $this->autoCloseSeconds();

        if (! $shouldRequestFeedback) {
            return $conversation;
        }

        if (! $conversation->rating_requested_at) {
            $conversation->forceFill([
                'rating_requested_at' => now(),
                'sla_status' => 'late',
            ])->save();

            $this->appendSystemMessage(
                $conversation,
                'Mohon maaf, operator belum membalas chat Anda lebih dari 10 menit. Silakan beri rating kepuasan agar performa operator bisa dievaluasi.',
            );
        }

        if ($shouldClose && $conversation->status !== 'closed') {
            $conversation->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
                'sla_status' => 'late',
            ])->save();

            $this->appendSystemMessage(
                $conversation,
                'Sesi chat ditutup otomatis karena belum ada jawaban operator selama 15 menit. Feedback Anda tetap tercatat untuk evaluasi layanan.',
            );
        }

        return $conversation->fresh();
    }

    public function enforceUnansweredOperatorChats(?Builder $query = null): int
    {
        $query ??= ChatConversation::query();

        $conversations = $query
            ->where('type', 'customer_operator')
            ->where('status', '!=', 'closed')
            ->whereNotNull('last_customer_message_at')
            ->whereNull('first_operator_response_at')
            ->where('last_customer_message_at', '<=', now()->subSeconds($this->feedbackSeconds()))
            ->get();

        $conversations->each(fn (ChatConversation $conversation) => $this->enforceUnansweredOperatorChat($conversation));

        return $conversations->count();
    }

    private function appendSystemMessage(ChatConversation $conversation, string $message): void
    {
        $chatMessage = $conversation->messages()->create([
            'sender_type' => 'system',
            'message' => $message,
            'is_read' => false,
        ]);

        try {
            broadcast(new MessageSent($chatMessage))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Chat SLA broadcast failed', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            app(NotificationService::class)->sendToUser(
                $conversation->customer,
                'Update chat operator',
                $message,
                [
                    'type' => 'chat_sla_feedback',
                    'conversation_id' => $conversation->id,
                    'message_id' => $chatMessage->id,
                    'url' => '/?'.http_build_query([
                        'open' => 'cs-chat',
                        'conversation_id' => $conversation->id,
                        'notification_type' => 'chat_sla_feedback',
                    ]),
                ],
            );
        } catch (\Throwable $exception) {
            Log::warning('Chat SLA notification failed', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
