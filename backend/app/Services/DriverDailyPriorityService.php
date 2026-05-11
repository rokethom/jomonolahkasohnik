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

    /**
     * @return array<int, array{start: string, end: string}>
     */
    public function windows(): array
    {
        $raw = $this->settings->get('driver_daily_priority_windows');
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $windows = is_array($decoded) ? $decoded : [
            ['start' => '05:00', 'end' => '11:00'],
            ['start' => '13:00', 'end' => '17:00'],
        ];

        return collect($windows)
            ->map(fn (mixed $window): array => [
                'start' => $this->normalizeTime(data_get($window, 'start', '05:00'), '05:00'),
                'end' => $this->normalizeTime(data_get($window, 'end', '11:00'), '11:00'),
            ])
            ->filter(fn (array $window): bool => $window['start'] !== $window['end'])
            ->values()
            ->all();
    }

    public function isWithinWindow(?Carbon $time = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $time ??= Carbon::now(self::TIMEZONE);
        $minutes = ((int) $time->format('H')) * 60 + (int) $time->format('i');

        foreach ($this->windows() as $window) {
            $start = $this->minutes($window['start']);
            $end = $this->minutes($window['end']);

            if ($start < $end && $minutes >= $start && $minutes <= $end) {
                return true;
            }

            if ($start > $end && ($minutes >= $start || $minutes <= $end)) {
                return true;
            }
        }

        return false;
    }

    public function markOnline(Driver $driver, bool $wasOnline): ?string
    {
        if (! $this->enabled() || $wasOnline || $driver->status !== 'active') {
            return null;
        }

        if (! $this->isWithinWindow()) {
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

        if (! $this->isWithinWindow()) {
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
            && $this->isWithinWindow()
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

    private function normalizeTime(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^\d{1}:\d{2}$/', $value) === 1) {
            return '0'.$value;
        }

        return $fallback;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
