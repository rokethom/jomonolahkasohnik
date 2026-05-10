<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Events\OrderPriceUpdated;
use App\Models\ChatConversation;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            $freshOrder = $order->fresh(['user', 'driver.user', 'adjustments']);
            $driverName = $driver->user?->name ?? 'Driver';
            $messageText = 'Tambahan service charge Rp '.number_format($amount, 0, ',', '.').' oleh '.$driverName.'. Alasan: '.$reason;

            $conversation = ChatConversation::query()
                ->where('order_id', $order->id)
                ->where('type', 'customer_driver')
                ->first();

            if ($conversation) {
                $message = $conversation->messages()->create([
                    'sender_type' => 'system',
                    'message' => $messageText,
                    'is_read' => false,
                ]);

                try {
                    broadcast(new MessageSent($message))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.order_adjustment_chat_failed', [
                        'order_id' => $order->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            try {
                OrderPriceUpdated::dispatch($freshOrder, $driver->user, [
                    'type' => 'driver_adjustment',
                    'amount' => $amount,
                    'reason' => $reason,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('broadcast.order_adjustment_price_failed', [
                    'order_id' => $order->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            $this->notifications->sendToUser(
                $order->user,
                'Penyesuaian biaya order',
                $messageText,
                [
                    'type' => 'order_adjustment',
                    'order_id' => $order->id,
                    'url' => '/?open=driver-chat&order_id='.$order->id.'&notification_type=order_adjustment',
                    'reason' => $reason,
                ],
            );

            return $adjustment->fresh(['order', 'driver.user']);
        });
    }
}
