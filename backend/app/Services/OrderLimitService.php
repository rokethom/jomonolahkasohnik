<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

class OrderLimitService
{
    public const LIMIT_MESSAGE = 'Anda melebihi batas order aktif. Silakan selesaikan salah satu pesanan terlebih dahulu.';

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function canCreateOrder(User $user): array
    {
        $activeOrders = $this->activeOrderCount($user);
        $maxOrders = $this->maxActiveOrders();

        return [
            'allowed' => $activeOrders < $maxOrders,
            'reason' => $activeOrders < $maxOrders ? null : self::LIMIT_MESSAGE,
            'active_orders' => $activeOrders,
            'max_orders' => $maxOrders,
        ];
    }

    public function activeOrderCount(User $user): int
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', $this->activeStatuses())
            ->count();
    }

    public function activeStatuses(): array
    {
        return [
            OrderStatus::Created->value,
            OrderStatus::SearchingDriver->value,
            OrderStatus::DriverAccepted->value,
            OrderStatus::DriverOnTheWay->value,
            OrderStatus::ArrivedPickup->value,
            OrderStatus::OnGoing->value,
        ];
    }

    private function maxActiveOrders(): int
    {
        return max(1, min(3, $this->settings->int('customer_max_active_orders', 3)));
    }
}
