<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\OperHandleRequest;
use App\Models\Order;
use App\Services\ChatService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CompleteOrder
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly SettingService $settings,
    )
    {
    }

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

            $hasPendingCrew = $order->crews()
                ->where('role', '!=', 'rider')
                ->where('status', 'pending')
                ->exists();

            if ($hasPendingCrew) {
                throw new RuntimeException('Order masih menunggu helper menerima slot crew.');
            }

            $waitMinutes = max(0, min(180, $this->settings->int('driver_complete_wait_minutes', 5)));
            $acceptedAtValue = data_get($order->pricing_breakdown, 'accepted_at');
            $acceptedAt = filled($acceptedAtValue) ? Carbon::parse($acceptedAtValue) : $order->updated_at;

            if ($waitMinutes > 0
                && in_array($order->status, [OrderStatus::DriverAccepted, OrderStatus::DriverOnTheWay, OrderStatus::ArrivedPickup, OrderStatus::OnGoing], true)
                && $acceptedAt?->greaterThan(now()->subMinutes($waitMinutes))) {
                throw new RuntimeException("Order baru bisa diselesaikan {$waitMinutes} menit setelah diterima driver.");
            }

            $oldStatus = $order->status;
            $order->update([
                'status' => OrderStatus::Completed,
                'completed_at' => now(),
            ]);
            $order->driver?->update(['is_available' => true]);
            $order->crews()
                ->whereNotNull('driver_id')
                ->where('role', '!=', 'rider')
                ->with('driver')
                ->get()
                ->each(fn ($crew) => $crew->driver?->update(['is_available' => true]));
            $this->chatService->closeForOrder($order);
            $freshOrder = $order->fresh(['user', 'driver.user']);

            DB::afterCommit(function () use ($freshOrder, $oldStatus): void {
                try {
                    OrderStatusUpdated::dispatch($freshOrder, $oldStatus, OrderStatus::Completed);
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.order_status_failed', [
                        'order_id' => $freshOrder->id,
                        'status' => OrderStatus::Completed->value,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            return $order->fresh(['user', 'driver.user', 'items']);
        });
    }
}
