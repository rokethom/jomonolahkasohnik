<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\ChatConversation;
use App\Models\OperHandleRequest;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CompleteOrder
{
    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status->isTerminal()) {
                throw new RuntimeException('Order already finished.');
            }

            $hasPendingOperHandle = OperHandleRequest::query()
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingOperHandle) {
                throw new RuntimeException('Order sedang menunggu approval oper handle.');
            }

            if (in_array($order->status, [OrderStatus::DriverAccepted, OrderStatus::DriverOnTheWay, OrderStatus::ArrivedPickup, OrderStatus::OnGoing], true)
                && $order->updated_at?->greaterThan(now()->subMinutes(5))) {
                throw new RuntimeException('Order baru bisa diselesaikan 5 menit setelah diterima driver.');
            }

            $oldStatus = $order->status;
            $order->update(['status' => OrderStatus::Completed]);
            $order->driver?->update(['is_available' => true]);
            ChatConversation::query()
                ->where('order_id', $order->id)
                ->where('type', 'customer_driver')
                ->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                ]);

            try {
                OrderStatusUpdated::dispatch($order->fresh(['user', 'driver.user']), $oldStatus, OrderStatus::Completed);
            } catch (\Throwable $exception) {
                Log::warning('broadcast.order_status_failed', [
                    'order_id' => $order->id,
                    'status' => OrderStatus::Completed->value,
                    'message' => $exception->getMessage(),
                ]);
            }

            return $order->fresh(['user', 'driver.user', 'items']);
        });
    }
}
