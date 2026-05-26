<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\MessageSent;
use App\Events\OrderPriceUpdated;
use App\Models\ChatConversation;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use RuntimeException;

class OrderAdjustmentService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingService $settings,
    )
    {
    }

    public function create(Order $order, Driver $driver, int $amount, string $reason): OrderAdjustment
    {
        if (! $this->settings->bool('driver_adjustment_enabled', true)) {
            throw new RuntimeException('Tambah service charge sedang dinonaktifkan oleh admin.');
        }

        $waitMinutes = max(0, min(180, $this->settings->int('driver_adjustment_wait_minutes', 5)));
        $acceptedAtValue = data_get($order->pricing_breakdown, 'accepted_at');
        $acceptedAt = filled($acceptedAtValue) ? Carbon::parse($acceptedAtValue) : $order->updated_at;

        if ($waitMinutes > 0
            && in_array($order->status, [OrderStatus::DriverAccepted, OrderStatus::DriverOnTheWay, OrderStatus::ArrivedPickup, OrderStatus::OnGoing], true)
            && $acceptedAt?->greaterThan(now()->subMinutes($waitMinutes))) {
            throw new RuntimeException("Tambah service charge baru aktif {$waitMinutes} menit setelah order diterima driver.");
        }

        $minimum = max(0, $this->settings->int('driver_adjustment_min_amount', 1000));
        $maximum = max($minimum, $this->settings->int('driver_adjustment_max_amount', 500000));
        $step = max(1, $this->settings->int('driver_adjustment_step_amount', 1000));

        if ($amount < $minimum || $amount > $maximum) {
            throw new RuntimeException('Nominal tambahan harus antara Rp '.number_format($minimum, 0, ',', '.').' sampai Rp '.number_format($maximum, 0, ',', '.').'.');
        }

        if ($amount % $step !== 0) {
            throw new RuntimeException('Nominal tambahan harus kelipatan Rp '.number_format($step, 0, ',', '.').'.');
        }

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
