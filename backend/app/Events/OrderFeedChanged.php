<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderFeedChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        private readonly string $eventName,
        private readonly array $meta = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders')];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        $status = $this->order->status;

        return [
            'order' => [
                'id' => $this->order->id,
                'order_code' => $this->order->order_code,
                'code' => $this->order->order_code,
                'branch_id' => $this->order->branch_id,
                'area_id' => $this->order->area_id,
                'driver_id' => $this->order->driver_id,
                'service_type' => $this->order->service_type,
                'service' => $this->order->service_type,
                'status' => is_object($status) && method_exists($status, '__toString')
                    ? (string) $status
                    : ($status->value ?? $status),
                'price' => $this->order->price,
                'service_charge' => $this->order->service_charge,
                'service_fee' => $this->order->service_charge,
                'extra_charge' => $this->order->extra_charge,
                'total_price' => $this->order->total_price,
                'total' => $this->order->total_price,
                'updated_at' => $this->order->updated_at?->toIso8601String(),
            ],
            ...$this->meta,
        ];
    }
}
