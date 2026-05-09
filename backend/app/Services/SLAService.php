<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\SlaLog;

class SLAService
{
    public function thresholdSeconds(): int
    {
        return (int) config('chat.sla_seconds', 300);
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
}
