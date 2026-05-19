<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public ChatMessage $message)
    {
        $this->message->loadMissing('conversation', 'sender', 'sticker');
    }

    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;
        $channels = [new PrivateChannel('chat.'.$conversation->id)];

        if ($conversation->order_id) {
            $channels[] = new PrivateChannel('chat.order.'.$conversation->order_id);
        }

        foreach ([$conversation->customer_id, $conversation->driver_id, $conversation->operator_id] as $userId) {
            if ($userId) {
                $channels[] = new PrivateChannel('chat.operator.'.$userId);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'chat_id' => $this->message->chat_conversation_id,
                'sender_id' => $this->message->sender_id,
                'sender_type' => $this->message->sender_type,
                'sender_name' => $this->message->sender?->name,
                'message' => $this->message->message,
                'image_url' => $this->message->image_url,
                'audio_url' => $this->message->audio_url,
                'audio_duration' => $this->message->audio_duration,
                'file_url' => $this->message->file_url,
                'file_name' => $this->message->file_name,
                'file_mime' => $this->message->file_mime,
                'file_size' => $this->message->file_size,
                'message_type' => $this->message->message_type,
                'sticker' => $this->message->sticker ? [
                    'id' => $this->message->sticker->id,
                    'name' => $this->message->sticker->name,
                    'category' => $this->message->sticker->category,
                    'image_url' => $this->message->sticker->image_url,
                ] : null,
                'is_read' => $this->message->is_read,
                'created_at' => $this->message->created_at?->toISOString(),
            ],
        ];
    }
}
