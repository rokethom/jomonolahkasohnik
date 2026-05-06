<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderAdjustment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderAdjustmentService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function create(Order $order, Driver $driver, int $amount, string $reason): OrderAdjustment
    {
        if ((int) $order->driver_id !== (int) $driver->id) {
            throw new RuntimeException('Driver tidak terhubung dengan order ini.');
        }

        return DB::transaction(function () use ($order, $driver, $amount, $reason): OrderAdjustment {
            $adjustment = OrderAdjustment::query()->create([
                'order_id' => $order->id,
                'driver_id' => $driver->id,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            $breakdown = $order->pricing_breakdown ?? [];
            $extraCharge = (int) $order->extra_charge + $amount;
            $total = (int) $order->price + (int) $order->service_charge + $extraCharge;

            $breakdown['extra_charge'] = $extraCharge;
            $breakdown['final_price'] = $total;
            $breakdown['adjustments'][] = [
                'amount' => $amount,
                'reason' => $reason,
                'created_at' => now()->toIso8601String(),
            ];

            $order->update([
                'extra_charge' => $extraCharge,
                'total_price' => $total,
                'pricing_breakdown' => $breakdown,
            ]);

            $this->notifications->sendToUser(
                $order->user,
                'Penyesuaian biaya order',
                "Tambahan jasa Rp ".number_format($amount, 0, ',', '.')." - {$reason}",
                ['type' => 'order_adjustment', 'order_id' => $order->id],
            );

            return $adjustment->fresh(['order', 'driver.user']);
        });
    }
}
