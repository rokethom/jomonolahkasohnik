<?php

namespace App\Listeners;

use App\Events\DriverAccepted;
use App\Events\OrderCreated;
use App\Events\OrderFeedChanged;
use App\Events\OrderPriceUpdated;
use App\Events\OrderStatusUpdated;

class BroadcastOrderFeedChanged
{
    public function handle(OrderCreated|OrderStatusUpdated|DriverAccepted|OrderPriceUpdated $event): void
    {
        $meta = [];
        $eventName = match (true) {
            $event instanceof OrderCreated => 'order.created',
            $event instanceof DriverAccepted => 'driver.accepted',
            $event instanceof OrderPriceUpdated => 'order.price.updated',
            default => 'order.status.updated',
        };

        if ($event instanceof OrderStatusUpdated) {
            $meta['old_status'] = $event->oldStatus->value;
            $meta['new_status'] = $event->newStatus->value;
        }

        if ($event instanceof OrderPriceUpdated) {
            $meta['actor_id'] = $event->actor?->id;
            $meta['actor_name'] = $event->actor?->name;
            $meta['change'] = $event->change;
            $meta['message'] = 'Harga order '.$event->order->order_code.' diperbarui.';
        }

        broadcast(new OrderFeedChanged($event->order, $eventName, $meta));
    }
}
