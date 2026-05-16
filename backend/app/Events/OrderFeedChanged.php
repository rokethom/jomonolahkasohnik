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
        $order = $this->order->fresh(['driver.user']) ?? $this->order;
        $status = $order->status;

        return [
            'order' => [
                'id' => $order->id,
                'order_code' => $order->order_code,
                'code' => $order->order_code,
                'branch_id' => $order->branch_id,
                'area_id' => $order->area_id,
                'driver_id' => $order->driver_id,
                'driver' => $order->driver?->user?->name,
                'service_type' => $order->service_type,
                'service' => $order->service_type,
                'status' => is_object($status) && method_exists($status, '__toString')
                    ? (string) $status
                    : ($status->value ?? $status),
                'price' => $order->price,
                'service_charge' => $order->service_charge,
                'service_fee' => $order->service_charge,
                'extra_charge' => $order->extra_charge,
                'total_price' => $order->total_price,
                'total' => $order->total_price,
                'updated_at' => $order->updated_at?->toIso8601String(),
            ],
            'broadcasted_at' => now()->toIso8601String(),
            ...$this->meta,
        ];
    }
}
