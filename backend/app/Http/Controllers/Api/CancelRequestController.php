<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CancelRequest;
use App\Models\ChatConversation;
use App\Models\Order;
use App\Services\CancelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CancelRequestController extends Controller
{
    public function store(Order $order, Request $request, CancelService $cancelService): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'max:4096'],
            'chat_conversation_id' => ['nullable', 'exists:chat_conversations,id'],
        ]);

        $this->authorizeCustomerDriverOrAdmin($order, $request);
        if (isset($payload['chat_conversation_id'])) {
            $this->authorizeChatParticipant((int) $payload['chat_conversation_id'], $request);
        }

        return response()->json(['data' => $cancelService->request($order, $request->user(), $payload)], 201);
    }

    public function approve(CancelRequest $cancelRequest, Request $request, CancelService $cancelService): JsonResponse
    {
        $this->authorizeOperator($request);

        return response()->json(['data' => $cancelService->approve($cancelRequest, $request->user())]);
    }

    public function reject(CancelRequest $cancelRequest, Request $request, CancelService $cancelService): JsonResponse
    {
        $this->authorizeOperator($request);

        return response()->json(['data' => $cancelService->reject($cancelRequest, $request->user())]);
    }

    private function authorizeOperator(Request $request): void
    {
        $role = $request->user()->role->value ?? $request->user()->role;
        abort_unless(in_array($role, ['admin', 'operator', 'gm', 'spv'], true), 403);
    }

    private function authorizeCustomerDriverOrAdmin(Order $order, Request $request): void
    {
        $role = $request->user()->role->value ?? $request->user()->role;

        if (in_array($role, ['admin', 'operator', 'gm', 'spv'], true) || (int) $order->user_id === (int) $request->user()->id) {
            return;
        }

        $order->loadMissing('driver');
        abort_unless($role === 'driver' && (int) optional($order->driver)->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeChatParticipant(int $conversationId, Request $request): void
    {
        $conversation = ChatConversation::query()->findOrFail($conversationId);
        $role = $request->user()->role->value ?? $request->user()->role;

        abort_unless(
            in_array($role, ['admin', 'operator', 'gm', 'spv'], true)
            || in_array((int) $request->user()->id, array_filter([
                $conversation->customer_id,
                $conversation->driver_id,
                $conversation->operator_id,
            ]), true),
            403,
        );
    }
}
