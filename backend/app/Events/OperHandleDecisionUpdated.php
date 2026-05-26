<?php

namespace App\Events;

use App\Models\OperHandleRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OperHandleDecisionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public OperHandleRequest $operHandle)
    {
        $this->operHandle->loadMissing(['order', 'driver.user']);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders')];
    }

    public function broadcastAs(): string
    {
        return 'oper-handle.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'oper_handle' => [
                'id' => $this->operHandle->id,
                'order_id' => $this->operHandle->order_id,
                'status' => $this->operHandle->status,
                'driver_id' => $this->operHandle->driver_id,
                'driver_username' => $this->operHandle->driver?->user?->username,
                'decided_at' => $this->operHandle->decided_at?->toIso8601String(),
            ],
        ];
    }
}
