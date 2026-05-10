<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Support\Carbon;

class DriverDailyPriorityService
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        private readonly SettingService $settings,
        private readonly MultiOrderService $multiOrder,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('driver_daily_priority_enabled', true);
    }

    public function holdMinutes(): int
    {
        return max(1, min(60, $this->settings->int('driver_daily_priority_hold_minutes', 3)));
    }

    public function markOnline(Driver $driver, bool $wasOnline): ?string
    {
        if (! $this->enabled() || $wasOnline || $driver->status !== 'active') {
            return null;
        }

        $today = $this->today();
        if ($driver->daily_priority_date?->toDateString() === $today) {
            return $driver->daily_priority_active ? 'Prioritas harian masih aktif dari online pertama hari ini.' : null;
        }

        if ($this->acceptedTodayCount($driver) > 0) {
            $driver->forceFill([
                'daily_priority_date' => $today,
                'daily_priority_active' => false,
                'first_online_at' => now(),
                'daily_priority_completed_at' => now(),
            ])->save();

            return null;
        }

        $driver->forceFill([
            'daily_priority_date' => $today,
            'daily_priority_active' => true,
            'first_online_at' => now(),
            'daily_priority_completed_at' => null,
            'daily_priority_order_id' => null,
        ])->save();

        return 'Prioritas harian aktif: Anda diprioritaskan untuk mendapat 1 order pertama jika memenuhi syarat.';
    }

    public function completeForAcceptedOrder(Driver $driver, Order $order): void
    {
        if ($driver->daily_priority_date?->toDateString() !== $this->today() || ! $driver->daily_priority_active) {
            return;
        }

        $driver->forceFill([
            'daily_priority_active' => false,
            'daily_priority_completed_at' => now(),
            'daily_priority_order_id' => $order->id,
        ])->save();
    }

    public function canSeeOrder(Driver $driver, Order $order): bool
    {
        if (! $this->enabled() || ! $this->isPendingOrder($order)) {
            return true;
        }

        if ($this->holdExpired($order) || $this->isPriorityActive($driver)) {
            return true;
        }

        return ! $this->hasEligiblePriorityDriver($order);
    }

    public function canAcceptOrder(Driver $driver, Order $order): bool
    {
        return $this->canSeeOrder($driver, $order);
    }

    public function isPriorityActive(Driver $driver): bool
    {
        return $this->enabled()
            && (bool) $driver->daily_priority_active
            && $driver->daily_priority_date?->toDateString() === $this->today()
            && $driver->daily_priority_completed_at === null
            && $this->acceptedTodayCount($driver) === 0;
    }

    private function hasEligiblePriorityDriver(Order $order): bool
    {
        return Driver::query()
            ->with(['user', 'setting'])
            ->where('status', 'active')
            ->where('is_available', true)
            ->where('daily_priority_active', true)
            ->whereDate('daily_priority_date', $this->today())
            ->whereNull('daily_priority_completed_at')
            ->get()
            ->contains(fn (Driver $driver): bool => $this->isPriorityActive($driver)
                && (bool) data_get($this->multiOrder->canAcceptOrder($driver, $order), 'can_accept'));
    }

    private function acceptedTodayCount(Driver $driver): int
    {
        $start = Carbon::now(self::TIMEZONE)->startOfDay()->timezone(config('app.timezone'));
        $end = Carbon::now(self::TIMEZONE)->endOfDay()->timezone(config('app.timezone'));

        return $driver->orders()
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->whereIn('status', [
                OrderStatus::DriverAccepted->value,
                OrderStatus::DriverOnTheWay->value,
                OrderStatus::ArrivedPickup->value,
                OrderStatus::OnGoing->value,
                OrderStatus::Completed->value,
            ])
            ->count();
    }

    private function holdExpired(Order $order): bool
    {
        $createdAt = $order->created_at ?: now();

        return $createdAt->copy()->addMinutes($this->holdMinutes())->lte(now());
    }

    private function isPendingOrder(Order $order): bool
    {
        $status = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        return in_array($status, [OrderStatus::Created->value, OrderStatus::SearchingDriver->value], true);
    }

    private function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }
}
