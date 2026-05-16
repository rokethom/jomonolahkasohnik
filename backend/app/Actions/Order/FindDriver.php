<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Driver;
use App\Models\Order;
use App\Services\MultiOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FindDriver
{
    public function __construct(private readonly MultiOrderService $multiOrder)
    {
    }

    public function handle(Order $order): array
    {
        $rejectionMessage = null;

        $result = DB::transaction(function () use ($order, &$rejectionMessage): array {
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
                $freshOrder = $order->fresh(['user', 'driver']);

                DB::afterCommit(function () use ($freshOrder, $oldStatus): void {
                    try {
                        OrderStatusUpdated::dispatch($freshOrder, $oldStatus, OrderStatus::Cancelled);
                    } catch (\Throwable $exception) {
                        Log::warning('broadcast.order_status_failed', [
                            'order_id' => $freshOrder->id,
                            'status' => OrderStatus::Cancelled->value,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                });

                $rejectionMessage = 'Order sudah timeout dan tidak bisa mencari driver.';

                return [
                    'order' => $freshOrder,
                    'driver' => null,
                ];
            }

            $driver = Driver::query()
                ->with(['user.currentLocation', 'setting'])
                ->where('is_available', true)
                ->where(function ($query) use ($order): void {
                    $query->where('can_accept_all_areas', true)
                        ->orWhereHas('user', function ($query) use ($order): void {
                            if ($order->area_id !== null) {
                                $query->where('area_id', $order->area_id)
                                    ->orWhere(function ($query) use ($order): void {
                                        $query->whereNull('area_id')->where('branch_id', $order->branch_id);
                                    });

                                return;
                            }

                            $query->where('branch_id', $order->branch_id);
                        });
                })
                ->when(
                    data_get($order->pricing_breakdown, 'preferred_vehicle_type') === 'motor',
                    fn ($query) => $query->where(function ($query): void {
                        $query->where('vehicle_type', 'motor')
                            ->orWhereJsonContains('vehicle_types', 'motor');
                    })
                )
                ->when(
                    data_get($order->pricing_breakdown, 'preferred_vehicle_type') === 'mobil',
                    fn ($query) => $query->where(function ($query): void {
                        $query->where('vehicle_type', 'mobil')
                            ->orWhereJsonContains('vehicle_types', 'mobil');
                    })->where(function ($query) use ($order): void {
                        $requiredRows = (int) data_get($order->pricing_breakdown, 'required_vehicle_seat_rows', 2);
                        $query->where('vehicle_seat_rows', '>=', $requiredRows);

                        if ($requiredRows <= 2) {
                            $query->orWhereNull('vehicle_seat_rows');
                        }
                    })
                )
                ->when(
                    data_get($order->pricing_breakdown, 'driver_preference') === 'ladies',
                    fn ($query) => $query->where('is_ladies_driver', true)
                )
                ->orderByRaw('current_lat IS NULL, current_lng IS NULL')
                ->get()
                ->first(fn (Driver $driver): bool => (bool) data_get($this->multiOrder->canAcceptOrder($driver, $order), 'can_accept'));

            $oldStatus = $order->status;
            $order->update(['status' => OrderStatus::SearchingDriver]);
            $freshOrder = $order->fresh(['user', 'driver']);

            DB::afterCommit(function () use ($freshOrder, $oldStatus): void {
                try {
                    OrderStatusUpdated::dispatch($freshOrder, $oldStatus, OrderStatus::SearchingDriver);
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.order_status_failed', [
                        'order_id' => $freshOrder->id,
                        'status' => OrderStatus::SearchingDriver->value,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            return [
                'order' => $order->fresh(['user', 'driver']),
                'driver' => $driver,
            ];
        });

        if ($rejectionMessage !== null) {
            throw new RuntimeException($rejectionMessage);
        }

        return $result;
    }
}
