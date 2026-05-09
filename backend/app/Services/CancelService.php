<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\CancelRequest;
use App\Models\ChatConversation;
use App\Models\Order;
use App\Models\User;

class CancelService
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly MessageService $messageService,
    ) {
    }

    public function request(Order $order, User $user, array $payload): CancelRequest
    {
        $role = $user->role->value ?? $user->role;
        $conversation = isset($payload['chat_conversation_id'])
            ? ChatConversation::query()->findOrFail($payload['chat_conversation_id'])
            : $this->chatService->forOrder($order, $role === 'customer' ? 'customer_operator' : 'driver_operator');

        $message = $this->messageService->send($conversation, $user, [
            'message' => sprintf(
                "Request cancel order %s\nAlasan: %s",
                $order->order_code ?? '#'.$order->id,
                $payload['reason'],
            ),
            'image' => $payload['image'] ?? null,
        ]);

        $order->forceFill(['status' => OrderStatus::PendingCancel])->save();

        return CancelRequest::create([
            'order_id' => $order->id,
            'chat_conversation_id' => $conversation->id,
            'requested_by' => $user->id,
            'reason' => $payload['reason'],
            'image_url' => $message->image_url,
            'status' => 'pending',
        ]);
    }

    public function approve(CancelRequest $cancelRequest, User $operator): CancelRequest
    {
        $cancelRequest->order->forceFill([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'notes' => trim(((string) $cancelRequest->order->notes)."\nCancel approved: {$cancelRequest->reason}"),
        ])->save();
        $cancelRequest->order->driver?->update(['is_available' => true]);
        $this->chatService->closeForOrder($cancelRequest->order);

        $cancelRequest->update([
            'status' => 'approved',
            'resolved_by' => $operator->id,
            'resolved_at' => now(),
        ]);

        return $cancelRequest->fresh('order');
    }

    public function reject(CancelRequest $cancelRequest, User $operator): CancelRequest
    {
        $cancelRequest->order->forceFill(['status' => OrderStatus::DriverAccepted])->save();

        $cancelRequest->update([
            'status' => 'rejected',
            'resolved_by' => $operator->id,
            'resolved_at' => now(),
        ]);

        return $cancelRequest->fresh('order');
    }
}
