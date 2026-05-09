<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\ChatConversation;
use App\Models\Order;
use App\Models\User;
use RuntimeException;

class ChatService
{
    public function startCustomerOperator(User $customer): ChatConversation
    {
        $role = $customer->role->value ?? $customer->role;
        if ($role === 'driver') {
            return ChatConversation::query()
                ->where('driver_id', $customer->id)
                ->where('type', 'driver_operator')
                ->where('status', '!=', 'closed')
                ->first()
                ?? ChatConversation::create([
                    'driver_id' => $customer->id,
                    'type' => 'driver_operator',
                    'status' => 'active',
                    'branch_id' => $customer->branch_id ?? null,
                ]);
        }

        return ChatConversation::query()
            ->where('customer_id', $customer->id)
            ->where('type', 'customer_operator')
            ->where('status', '!=', 'closed')
            ->first()
            ?? ChatConversation::create([
                'customer_id' => $customer->id,
                'type' => 'customer_operator',
                'status' => 'active',
                'branch_id' => $customer->branch_id ?? null,
            ]);
    }

    public function forOrder(Order $order, string $type): ChatConversation
    {
        $existing = ChatConversation::query()
            ->where('order_id', $order->id)
            ->where('type', $type)
            ->first();

        if ($existing) {
            return $existing;
        }

        if ($type === 'customer_driver' && $order->status !== OrderStatus::DriverAccepted) {
            throw new RuntimeException('Chat customer-driver aktif setelah order accepted.');
        }

        $order->loadMissing('user', 'driver.user');

        return ChatConversation::firstOrCreate(
            ['order_id' => $order->id, 'type' => $type],
            [
                'customer_id' => $order->user_id,
                'driver_id' => $order->driver?->user_id,
                'branch_id' => $order->branch_id ?? null,
                'status' => $order->status->isTerminal() ? 'closed' : 'active',
                'closed_at' => $order->status->isTerminal() ? now() : null,
            ],
        );
    }

    public function assertWritable(ChatConversation $conversation): void
    {
        if ($conversation->status === 'closed' || $conversation->closed_at) {
            throw new RuntimeException('Chat sudah read-only.');
        }

        if ($conversation->type === 'customer_driver') {
            $conversation->loadMissing('order');
            if ($conversation->order?->status !== OrderStatus::DriverAccepted) {
                throw new RuntimeException('Chat customer-driver belum aktif atau sudah selesai.');
            }
        }
    }

    public function closeForOrder(Order $order): void
    {
        ChatConversation::query()
            ->where('order_id', $order->id)
            ->whereIn('type', ['customer_driver', 'customer_operator', 'driver_operator'])
            ->where(function ($query): void {
                $query->where('status', '!=', 'closed')->orWhereNull('closed_at');
            })
            ->update([
                'status' => 'closed',
                'closed_at' => now(),
            ]);
    }
}
