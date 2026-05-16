<?php

namespace App\Events;

use App\Models\Order;
use App\Services\OrderFeedbackService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DriverAccepted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('order.'.$this->order->id),
            new PrivateChannel('user.'.$this->order->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'driver.accepted';
    }

    public function broadcastWith(): array
    {
        $order = $this->order->fresh(['driver.user']) ?? $this->order;

        return [
            'order' => $order->toArray(),
            'feedback' => app(OrderFeedbackService::class)->driverAccepted($order),
            'broadcasted_at' => now()->toIso8601String(),
        ];
    }
}
