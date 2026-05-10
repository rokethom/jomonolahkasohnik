<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Exceptions\OrderLimitExceededException;
use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly SettingService $settings,
        private readonly OrderLimitService $limits,
        private readonly OrderOperationService $operations,
        private readonly NotificationService $notifications,
        private readonly ChatService $chatService,
        private readonly BranchDetectionService $branches,
    )
    {
    }

    public function assertCustomerCanCreate(User $user, string $serviceType): void
    {
        $this->cancelExpiredCreatedOrders();

        if ($message = $this->operations->closedMessage()) {
            throw ValidationException::withMessages([
                'order_closed' => $message,
            ]);
        }

        $limit = $this->limits->canCreateOrder($user);
        if (! $limit['allowed']) {
            throw new OrderLimitExceededException(
                $limit['reason'],
                $limit['active_orders'],
                $limit['max_orders'],
            );
        }

        if (! $this->isGiftOrder($serviceType) && $this->isOutsideRegisteredArea($user)) {
            throw ValidationException::withMessages([
                'service_type' => 'Customer di luar cabang hanya bisa memakai layanan Gift Order.',
            ]);
        }
    }

    public function cancelExpiredCreatedOrders(): int
    {
        $orders = Order::query()
            ->with(['user', 'driver.user'])
            ->whereIn('status', [OrderStatus::Created->value, OrderStatus::SearchingDriver->value])
            ->whereNull('driver_id')
            ->where(function ($query): void {
                $query->where('expired_at', '<=', now())
                    ->orWhere(fn ($query) => $query->whereNull('expired_at')->where('created_at', '<=', now()->subMinutes(10)));
            })
            ->limit(100)
            ->get();

        foreach ($orders as $order) {
            $oldStatus = $order->status;

            $order->update([
                'status' => OrderStatus::Cancelled->value,
                'cancelled_at' => now(),
                'notes' => DB::raw("CONCAT(COALESCE(notes, ''), '\nAuto-cancel: driver timeout 10 menit.')"),
            ]);
            $this->chatService->closeForOrder($order);

            try {
                $freshOrder = $order->fresh(['user', 'driver.user']);
                $feedback = app(OrderFeedbackService::class)->statusUpdated($freshOrder, $oldStatus, OrderStatus::Cancelled);
                OrderStatusUpdated::dispatch($freshOrder, $oldStatus, OrderStatus::Cancelled);
                $this->notifications->sendToUser(
                    $freshOrder->user,
                    'Order dibatalkan otomatis',
                    $feedback['message'] ?? 'Maaf, order dibatalkan otomatis karena tidak ada driver yang menerima.',
                    [
                        'type' => 'order_auto_cancelled',
                        'order_id' => $freshOrder->id,
                        'order_code' => $freshOrder->order_code,
                        'url' => '/?open=history&order_id='.$freshOrder->id.'&notification_type=order_auto_cancelled',
                    ],
                );
            } catch (\Throwable $exception) {
                Log::warning('broadcast.expired_order_status_failed', [
                    'order_id' => $order->id,
                    'status' => OrderStatus::Cancelled->value,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $orders->count();
    }

    public function maxTextPoints(): int
    {
        return max(3, min(5, $this->settings->int('max_text_order_points', 3)));
    }

    public function locationHash(array $payload): string
    {
        return hash('sha256', implode('|', [
            $payload['pickup_address'] ?? '',
            $payload['pickup_lat'] ?? '',
            $payload['pickup_lng'] ?? '',
            $payload['destination_address'] ?? '',
            $payload['destination_lat'] ?? '',
            $payload['destination_lng'] ?? '',
        ]));
    }

    public function resolveTargetBranchId(array $payload, ?int $fallbackBranchId = null): ?int
    {
        foreach ([
            ['destination_lat', 'destination_lng'],
            ['pickup_lat', 'pickup_lng'],
        ] as [$latKey, $lngKey]) {
            if (! isset($payload[$latKey], $payload[$lngKey])) {
                continue;
            }

            $detected = $this->branches->detect((float) $payload[$latKey], (float) $payload[$lngKey]);
            $branchId = $detected['branch']?->id ?? null;
            if ($branchId) {
                return (int) $branchId;
            }
        }

        return isset($payload['branch_id'])
            ? (int) $payload['branch_id']
            : $fallbackBranchId;
    }

    private function isGiftOrder(string $serviceType): bool
    {
        return in_array(strtolower($serviceType), ['gift', 'gift_order', 'go'], true);
    }

    private function isOutsideRegisteredArea(User $user): bool
    {
        if ($user->branch_id === null) {
            return true;
        }

        $latest = $user->latestLocationLog;
        return $latest !== null && ! $latest->is_valid;
    }
}
