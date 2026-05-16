<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpdateOrderStatus
{
    private const DRIVER_ALLOWED_STATUSES = [
        OrderStatus::DriverOnTheWay,
        OrderStatus::ArrivedPickup,
        OrderStatus::OnGoing,
    ];

    public function handle(Order $order, OrderStatus $status): Order
    {
        return DB::transaction(function () use ($order, $status): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($status, self::DRIVER_ALLOWED_STATUSES, true)) {
                throw new RuntimeException('Status is not allowed for this endpoint.');
            }

            if ($order->status->isTerminal()) {
                throw new RuntimeException('Order already finished.');
            }

            if ($order->driver_id === null) {
                throw new RuntimeException('Order has no accepted driver.');
            }

            $oldStatus = $order->status;
            $order->update(['status' => $status]);
            $freshOrder = $order->fresh(['user', 'driver.user']);

            DB::afterCommit(function () use ($freshOrder, $oldStatus, $status): void {
                try {
                    OrderStatusUpdated::dispatch($freshOrder, $oldStatus, $status);
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.order_status_failed', [
                        'order_id' => $freshOrder->id,
                        'status' => $status->value,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            return $order->fresh(['user', 'driver.user', 'items']);
        });
    }
}
