<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Events\DriverAccepted;
use App\Events\MessageSent;
use App\Events\OrderStatusUpdated;
use App\Models\ChatConversation;
use App\Models\Driver;
use App\Models\Order;
use App\Services\MultiOrderService;
use App\Services\NotificationService;
use App\Services\DriverDailyPriorityService;
use App\Services\DriverFinanceService;
use App\Services\ChatService;
use App\Services\OrderCrewDecisionService;
use App\Services\SuspendService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AcceptOrder
{
    public function __construct(
        private readonly MultiOrderService $multiOrder,
        private readonly DriverDailyPriorityService $dailyPriority,
        private readonly SuspendService $suspensions,
        private readonly NotificationService $notifications,
        private readonly DriverFinanceService $finance,
        private readonly ChatService $chatService,
        private readonly OrderCrewDecisionService $crewDecisions,
    )
    {
    }

    public function handle(Order $order, Driver $driver, bool $ignoreDailyPriority = false): Order
    {
        try {
            return Cache::lock("orders:accept:{$order->id}", 10)->block(3, function () use ($order, $driver, $ignoreDailyPriority): Order {
                return $this->acceptWithDatabaseLock($order, $driver, $ignoreDailyPriority);
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Order sedang diproses driver lain. Silakan refresh daftar order.');
        }
    }

    private function acceptWithDatabaseLock(Order $order, Driver $driver, bool $ignoreDailyPriority): Order
    {
        $rejectionMessage = null;

        $result = DB::transaction(function () use ($order, $driver, $ignoreDailyPriority, &$rejectionMessage): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $driver = Driver::query()->with(['user', 'setting'])->lockForUpdate()->findOrFail($driver->id);

            if ($order->driver_id !== null) {
                throw new RuntimeException('Order has already been accepted by another driver.');
            }

            if ($order->expired_at && $order->expired_at->lte(now())) {
                $oldStatus = $order->status;
                $order->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'notes' => trim(((string) $order->notes)."\nAuto-cancel: driver timeout 10 menit."),
                ]);
                $this->chatService->closeForOrder($order);
                $cancelledOrder = $order->fresh(['user', 'driver.user']);

                DB::afterCommit(function () use ($cancelledOrder, $oldStatus): void {
                    try {
                        OrderStatusUpdated::dispatch($cancelledOrder, $oldStatus, OrderStatus::Cancelled);
                    } catch (\Throwable $exception) {
                        Log::warning('broadcast.accept_timeout_status_failed', [
                            'order_id' => $cancelledOrder->id,
                            'status' => OrderStatus::Cancelled->value,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                });

                $rejectionMessage = 'Order sudah timeout dan tidak bisa diterima.';

                return $cancelledOrder;
            }

            if (! $this->suspensions->canAcceptOrder($driver)) {
                throw new RuntimeException('Driver tidak bisa menerima order karena suspend setoran/permanent.');
            }

            $deposit = $this->finance->monthlyDeposit($driver, now()->subMonth());
            if ($this->finance->depositBlocksOrders($deposit)) {
                $this->suspensions->suspendUnpaid($driver, DriverFinanceService::UNPAID_SUSPEND_REASON);
                throw new RuntimeException(DriverFinanceService::UNPAID_SUSPEND_REASON);
            }

            if (! $driver->is_available) {
                throw new RuntimeException('Status driver OFF. Aktifkan ON terlebih dahulu untuk menerima order.');
            }

            if (! in_array($order->status, [OrderStatus::Created, OrderStatus::SearchingDriver], true)) {
                throw new RuntimeException('Order is not available for acceptance.');
            }

            $eligibility = $this->multiOrder->canAcceptOrder($driver, $order);
            if (! $eligibility['can_accept']) {
                throw new RuntimeException(match ($eligibility['reason'] ?? null) {
                    'tidak searah' => 'Order tidak searah dengan perjalanan Anda',
                    'di luar area driver' => 'Order berada di luar area/cabang driver Anda.',
                    'kendaraan tidak sesuai' => 'Kendaraan driver tidak sesuai dengan kebutuhan order.',
                    'layanan tidak aktif untuk driver' => 'Layanan order ini belum aktif untuk akun driver Anda.',
                    'khusus driver ladies' => 'Order ini khusus untuk driver Ladies.',
                    'multi order nonaktif' => 'Multi order sedang nonaktif.',
                    default => 'Driver sudah mencapai batas order aktif.',
                });
            }

            if (! $ignoreDailyPriority && ! $this->dailyPriority->canAcceptOrder($driver, $order)) {
                throw new RuntimeException('Order ini sedang diprioritaskan untuk driver yang pertama online hari ini.');
            }

            $breakdown = $order->pricing_breakdown ?? [];
            $breakdown['accepted_at'] = now()->toIso8601String();

            $updated = Order::query()
                ->whereKey($order->id)
                ->whereNull('driver_id')
                ->whereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
                ->update([
                    'driver_id' => $driver->id,
                    'direction_bearing' => $this->multiOrder->bearingFor($order),
                    'is_multi_order' => ($eligibility['active_order_count'] ?? 0) > 0,
                    'status' => OrderStatus::DriverAccepted->value,
                    'pricing_breakdown' => $breakdown,
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('Order has already been accepted by another driver.');
            }

            $order = $order->fresh();
            $this->crewDecisions->createPendingHelperCrew($order);
            $this->dailyPriority->completeForAcceptedOrder($driver, $order);
            $acceptedOrder = $order->fresh(['user', 'driver.user', 'items']);
            $driverName = $acceptedOrder->driver?->user?->name ?? 'driver';
            $crewDecision = data_get($acceptedOrder->pricing_breakdown, 'crew_decision');
            $helperLabel = is_array($crewDecision) ? (string) ($crewDecision['helper_label'] ?? 'Helper') : null;

            $conversation = ChatConversation::updateOrCreate(
                ['order_id' => $acceptedOrder->id, 'type' => 'customer_driver'],
                [
                    'customer_id' => $acceptedOrder->user_id,
                    'driver_id' => $acceptedOrder->driver?->user_id,
                    'branch_id' => $acceptedOrder->branch_id,
                    'status' => 'active',
                    'closed_at' => null,
                ],
            );

            $message = $conversation->messages()->create([
                'sender_type' => 'system',
                'message' => $helperLabel
                    ? "Pesanan Anda telah diterima oleh {$driverName}. Sistem sedang mencari {$helperLabel}."
                    : "Pesanan Anda telah diterima oleh {$driverName}",
                'is_read' => false,
            ]);

            DB::afterCommit(function () use ($acceptedOrder, $message, $helperLabel, $driverName): void {
                try {
                    DriverAccepted::dispatch($acceptedOrder);
                    broadcast(new MessageSent($message))->toOthers();
                } catch (\Throwable $exception) {
                    Log::warning('broadcast.driver_accepted_failed', [
                        'order_id' => $acceptedOrder->id,
                        'message' => $exception->getMessage(),
                    ]);
                }

                try {
                    $this->notifications->sendToUser(
                        $acceptedOrder->user,
                        'Order diterima driver',
                        $helperLabel ? "Pesanan Anda telah diterima oleh {$driverName}. Sistem sedang mencari {$helperLabel}." : "Pesanan Anda telah diterima oleh {$driverName}",
                        [
                            'type' => 'driver_accepted',
                            'order_id' => $acceptedOrder->id,
                            'url' => '/?open=driver-chat&order_id='.$acceptedOrder->id.'&notification_type=driver_accepted',
                        ],
                    );
                } catch (\Throwable $exception) {
                    Log::warning('notification.driver_accepted_failed', [
                        'order_id' => $acceptedOrder->id,
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

            if ($helperLabel) {
                $orderId = $acceptedOrder->id;
                $branchId = $acceptedOrder->branch_id;
                $mainDriverId = $driver->id;

                DB::afterCommit(function () use ($orderId, $branchId, $mainDriverId, $helperLabel): void {
                    Driver::query()
                        ->with('user')
                        ->where('status', 'active')
                        ->where('is_available', true)
                        ->where('id', '!=', $mainDriverId)
                        ->where(function ($query) use ($branchId): void {
                            $query->where('can_accept_all_areas', true)
                                ->orWhereHas('user', fn ($query) => $query->where('branch_id', $branchId));
                        })
                        ->limit(50)
                        ->get()
                        ->each(function (Driver $candidate) use ($helperLabel, $orderId): void {
                            try {
                                $this->notifications->sendToUser(
                                    $candidate->user,
                                    'Slot helper tersedia',
                                    "{$helperLabel} dibutuhkan untuk order #{$orderId}.",
                                    [
                                        'type' => 'crew_helper_needed',
                                        'order_id' => $orderId,
                                        'url' => '/?open=orders&notification_type=crew_helper_needed&order_id='.$orderId,
                                    ],
                                );
                            } catch (\Throwable $exception) {
                                Log::warning('notification.crew_helper_needed_failed', [
                                    'order_id' => $orderId,
                                    'driver_id' => $candidate->id,
                                    'message' => $exception->getMessage(),
                                ]);
                            }
                        });
                });
            }

            return $acceptedOrder;
        });

        if ($rejectionMessage !== null) {
            throw new RuntimeException($rejectionMessage);
        }

        return $result;
    }
}
