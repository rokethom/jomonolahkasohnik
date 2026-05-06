<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CancelOrder
{
    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status->isTerminal()) {
                throw new RuntimeException('Order already finished.');
            }

            $oldStatus = $order->status;
            $order->update(['status' => OrderStatus::Cancelled]);
            $order->driver?->update(['is_available' => true]);

            try {
                OrderStatusUpdated::dispatch($order->fresh(['user', 'driver.user']), $oldStatus, OrderStatus::Cancelled);
            } catch (\Throwable $exception) {
                Log::warning('broadcast.order_status_failed', [
                    'order_id' => $order->id,
                    'status' => OrderStatus::Cancelled->value,
                    'message' => $exception->getMessage(),
                ]);
            }

            return $order->fresh(['user', 'driver.user', 'items']);
        });
    }
}
