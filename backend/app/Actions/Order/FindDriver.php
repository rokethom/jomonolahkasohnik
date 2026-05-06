<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FindDriver
{
    public function handle(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status->isTerminal()) {
                throw new RuntimeException('Order already finished.');
            }

            if ($order->driver_id === null && $order->expired_at && $order->expired_at->lte(now())) {
                $oldStatus = $order->status;
                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'notes' => trim(((string) $order->notes)."\nAuto-cancel: driver timeout 10 menit."),
                ]);

                try {
                    OrderStatusUpdated::dispatch($order->fresh(['user', 'driver']), $oldStatus, OrderStatus::Cancelled);
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.order_status_failed', [
                        'order_id' => $order->id,
                        'status' => OrderStatus::Cancelled->value,
                        'message' => $exception->getMessage(),
                    ]);
                }

                throw new RuntimeException('Order sudah timeout dan tidak bisa mencari driver.');
            }

            $driver = Driver::query()
                ->where('is_available', true)
                ->orderByRaw('current_lat IS NULL, current_lng IS NULL')
                ->first();

            $oldStatus = $order->status;
            $order->update(['status' => OrderStatus::SearchingDriver]);

            try {
                OrderStatusUpdated::dispatch($order->fresh(['user', 'driver']), $oldStatus, OrderStatus::SearchingDriver);
            } catch (\Throwable $exception) {
                Log::warning('broadcast.order_status_failed', [
                    'order_id' => $order->id,
                    'status' => OrderStatus::SearchingDriver->value,
                    'message' => $exception->getMessage(),
                ]);
            }

            return [
                'order' => $order->fresh(['user', 'driver']),
                'driver' => $driver,
            ];
        });
    }
}
