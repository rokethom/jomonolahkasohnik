<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPriceUpdated implements ShouldBroadcastNow
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
            new PrivateChannel('orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.price.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'order_code' => $this->order->order_code,
                'code' => $this->order->order_code,
                'service_type' => $this->order->service_type,
                'service' => $this->order->service_type,
                'status' => $this->order->status->value,
                'price' => $this->order->price,
                'service_charge' => $this->order->service_charge,
                'service_fee' => $this->order->service_charge,
                'extra_charge' => $this->order->extra_charge,
                'total_price' => $this->order->total_price,
                'total' => $this->order->total_price,
            ],
        ];
    }
}
