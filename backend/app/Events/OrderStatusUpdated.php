<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderFeedbackService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public OrderStatus $oldStatus,
        public OrderStatus $newStatus,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders'),
            new PrivateChannel('order.'.$this->order->id),
            new PrivateChannel('user.'.$this->order->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->order->toArray(),
            'old_status' => $this->oldStatus->value,
            'new_status' => $this->newStatus->value,
            'feedback' => app(OrderFeedbackService::class)->statusUpdated($this->order, $this->oldStatus, $this->newStatus),
        ];
    }
}
