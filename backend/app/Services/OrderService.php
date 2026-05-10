<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Exceptions\OrderLimitExceededException;
use App\Models\OperHandleRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
        $cancelled = 0;
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

        $cancelled += $orders->count();
        $cancelled += $this->cancelExpiredMultiCrewOrders();

        return $cancelled;
    }

    public function cancelExpiredMultiCrewOrders(): int
    {
        if (! $this->settings->bool('multi_crew_auto_cancel_enabled', true)) {
            return 0;
        }

        $minutes = max(1, min(180, $this->settings->int('multi_crew_auto_cancel_minutes', 7)));
        $orders = Order::query()
            ->with(['user', 'driver.user', 'crews'])
            ->whereNotNull('driver_id')
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
            ])
            ->whereNotNull('pricing_breakdown->crew_decision')
            ->whereHas('crews', fn ($query) => $query
                ->where('role', '!=', 'rider')
                ->where('status', 'pending'))
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->limit(100)
            ->get()
            ->filter(fn (Order $order): bool => $this->crewWaitingStartedAt($order)?->lte(now()->subMinutes($minutes)) ?? false);

        foreach ($orders as $order) {
            $oldStatus = $order->status;
            $helperLabel = (string) data_get($order->pricing_breakdown, 'crew_decision.helper_label', 'helper');
            $reason = "Auto-cancel: multi-crew timeout, {$helperLabel} belum menerima dalam {$minutes} menit.";

            $cancelled = DB::transaction(function () use ($order, $reason): ?Order {
                $lockedOrder = Order::query()
                    ->with(['user', 'driver.user', 'crews'])
                    ->lockForUpdate()
                    ->find($order->id);

                if (! $lockedOrder || $lockedOrder->status->isTerminal()) {
                    return null;
                }

                $hasPendingCrew = $lockedOrder->crews()
                    ->where('role', '!=', 'rider')
                    ->where('status', 'pending')
                    ->exists();

                if (! $hasPendingCrew) {
                    return null;
                }

                $lockedOrder->update([
                    'status' => OrderStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'notes' => trim(((string) $lockedOrder->notes)."\n{$reason}"),
                ]);
                $lockedOrder->driver?->update(['is_available' => true]);
                $lockedOrder->crews()
                    ->where('role', '!=', 'rider')
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);
                $this->chatService->closeForOrder($lockedOrder);

                return $lockedOrder->fresh(['user', 'driver.user']);
            });

            if (! $cancelled) {
                continue;
            }

            try {
                app(OrderFeedbackService::class)->statusUpdated($cancelled, $oldStatus, OrderStatus::Cancelled);
                OrderStatusUpdated::dispatch($cancelled, $oldStatus, OrderStatus::Cancelled);
                $this->notifications->sendToUser(
                    $cancelled->user,
                    'Order dibatalkan otomatis',
                    $this->multiCrewAutoCancelMessage($cancelled, $minutes),
                    [
                        'type' => 'order_auto_cancelled',
                        'order_id' => $cancelled->id,
                        'order_code' => $cancelled->order_code,
                        'url' => '/?open=history&order_id='.$cancelled->id.'&notification_type=order_auto_cancelled',
                    ],
                );
            } catch (\Throwable $exception) {
                Log::warning('broadcast.multi_crew_timeout_status_failed', [
                    'order_id' => $cancelled->id,
                    'status' => OrderStatus::Cancelled->value,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $orders->count();
    }

    public function autoCompleteForgottenDriverOrders(): int
    {
        $orders = Order::query()
            ->with(['user', 'driver.user'])
            ->whereNotNull('driver_id')
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
            ])
            ->where('created_at', '<=', now()->subMinutes(30))
            ->limit(100)
            ->get()
            ->filter(fn (Order $order): bool => $this->acceptedAtForAutoComplete($order)?->lte(now()->subMinutes(30)) ?? false);

        foreach ($orders as $order) {
            $oldStatus = $order->status;

            $completed = DB::transaction(function () use ($order): ?Order {
                $lockedOrder = Order::query()->with(['user', 'driver.user'])->lockForUpdate()->find($order->id);

                if (! $lockedOrder || $lockedOrder->status->isTerminal()) {
                    return null;
                }

                if (! in_array($lockedOrder->status, [
                    OrderStatus::DriverAccepted,
                    OrderStatus::DriverOnTheWay,
                    OrderStatus::ArrivedPickup,
                    OrderStatus::OnGoing,
                ], true)) {
                    return null;
                }

                if (($this->acceptedAtForAutoComplete($lockedOrder)?->lte(now()->subMinutes(30)) ?? false) === false) {
                    return null;
                }

                $hasPendingOperHandle = OperHandleRequest::query()
                    ->where('order_id', $lockedOrder->id)
                    ->where('status', 'pending')
                    ->exists();

                if ($hasPendingOperHandle) {
                    return null;
                }

                $lockedOrder->update([
                    'status' => OrderStatus::Completed,
                    'notes' => trim(((string) $lockedOrder->notes)."\nAuto-complete: driver lupa klik selesai setelah 30 menit."),
                ]);
                $lockedOrder->driver?->update(['is_available' => true]);
                $this->chatService->closeForOrder($lockedOrder);

                return $lockedOrder->fresh(['user', 'driver.user']);
            });

            if (! $completed) {
                continue;
            }

            try {
                OrderStatusUpdated::dispatch($completed, $oldStatus, OrderStatus::Completed);
                $feedback = app(OrderFeedbackService::class)->statusUpdated($completed, $oldStatus, OrderStatus::Completed);
                $this->notifications->sendToUser(
                    $completed->user,
                    $feedback['title'] ?? 'Order selesai otomatis',
                    $feedback['message'] ?? 'Order selesai otomatis setelah 30 menit.',
                    [
                        'type' => 'order_completed',
                        'order_id' => $completed->id,
                        'order_code' => $completed->order_code,
                        'url' => '/?open=history&order_id='.$completed->id.'&notification_type=order_completed',
                    ],
                );
            } catch (\Throwable $exception) {
                Log::warning('broadcast.auto_complete_order_failed', [
                    'order_id' => $completed->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $orders->count();
    }

    private function acceptedAtForAutoComplete(Order $order): ?CarbonInterface
    {
        $acceptedAt = data_get($order->pricing_breakdown, 'accepted_at');

        if (filled($acceptedAt)) {
            try {
                return Carbon::parse($acceptedAt);
            } catch (\Throwable) {
                return $order->updated_at;
            }
        }

        return $order->updated_at;
    }

    private function crewWaitingStartedAt(Order $order): ?CarbonInterface
    {
        $acceptedAt = data_get($order->pricing_breakdown, 'accepted_at');

        if (filled($acceptedAt)) {
            try {
                return Carbon::parse($acceptedAt);
            } catch (\Throwable) {
                return $order->updated_at;
            }
        }

        return $order->updated_at;
    }

    private function multiCrewAutoCancelMessage(Order $order, int $minutes): string
    {
        $template = $this->settings->get('multi_crew_auto_cancel_message', 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.')
            ?: 'Maaf, order {order_code} dibatalkan otomatis karena helper belum menerima dalam {minutes} menit.';

        return strtr($template, [
            '{order_code}' => $order->order_code ?? '#'.$order->id,
            '{minutes}' => (string) $minutes,
            '{helper_label}' => (string) data_get($order->pricing_breakdown, 'crew_decision.helper_label', 'helper'),
            '{driver_name}' => $order->driver?->user?->name ?? 'driver',
        ]);
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
