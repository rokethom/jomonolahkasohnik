<?php

namespace App\Events;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingIndicator implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ChatConversation $conversation,
        public int $userId,
        public bool $typing,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'typing';
    }
}
