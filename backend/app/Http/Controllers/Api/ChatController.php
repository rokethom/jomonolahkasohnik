<?php

namespace App\Http\Controllers\Api;

use App\Events\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\Order;
use App\Models\User;
use App\Services\BotService;
use App\Services\ChatService;
use App\Services\MessageService;
use App\Services\SLAService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChatController extends Controller
{
    public function startOperator(Request $request, ChatService $chatService, MessageService $messageService, BotService $botService): JsonResponse
    {
        $conversation = $chatService->startCustomerOperator($request->user());

        if ($conversation->type === 'customer_operator' && $conversation->messages()->doesntExist()) {
            $message = $conversation->messages()->create([
                'sender_type' => 'bot',
                'message' => $botService->welcome(),
                'is_read' => false,
            ]);
            $this->broadcastSafely(new \App\Events\MessageSent($message));
        }

        return response()->json(['data' => $this->conversationPayload($conversation->load(['latestMessage', 'operator']))]);
    }

    public function startOrder(Order $order, Request $request, ChatService $chatService): JsonResponse
    {
        $this->authorizeOrderParticipant($order, $request);

        try {
            $conversation = $chatService->forOrder($order, $request->string('type', 'customer_driver')->toString());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->conversationPayload($conversation->load('operator'))]);
    }

    public function orderMessages(Order $order, Request $request, ChatService $chatService): JsonResponse
    {
        $this->authorizeOrderParticipant($order, $request);

        try {
            $conversation = $chatService->forOrder($order, 'customer_driver');
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $conversation->messages()
                ->with('sender')
                ->oldest()
                ->get(),
            'conversation' => $conversation,
        ]);
    }

    public function messages(ChatConversation $conversation, Request $request, SLAService $slaService): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request);
        $conversation = $slaService->enforceUnansweredOperatorChat($conversation->refresh());

        return response()->json([
            'data' => $conversation->messages()
                ->with('sender')
                ->latest()
                ->paginate($request->integer('per_page', 30)),
            'conversation' => $this->conversationPayload($conversation),
        ]);
    }

    public function send(ChatConversation $conversation, Request $request, ChatService $chatService, MessageService $messageService, BotService $botService, SLAService $slaService): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request);
        $conversation = $slaService->enforceUnansweredOperatorChat($conversation->refresh());

        try {
            $chatService->assertWritable($conversation);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $payload = $request->validate([
            'message' => ['nullable', 'required_without_all:image,audio', 'string', 'max:4000'],
            'image' => ['nullable', 'required_without_all:message,audio', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'audio' => ['nullable', 'required_without_all:message,image', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/webm,video/webm', 'max:8192'],
            'audio_duration' => ['nullable', 'integer', 'min:1'],
        ]);

        $message = $messageService->send($conversation, $request->user(), $payload);

        if ($conversation->type === 'customer_operator' && $botReply = $botService->replyFor($payload['message'] ?? null)) {
            $bot = $conversation->messages()->create([
                'sender_type' => 'bot',
                'message' => $botReply,
            ]);
            $this->broadcastSafely(new \App\Events\MessageSent($bot));
        }

        if ($conversation->type === 'customer_operator' && $botService->shouldAssignHuman($payload['message'] ?? null)) {
            $conversation->forceFill(['status' => 'waiting'])->save();
        }

        return response()->json(['data' => $message], 201);
    }

    public function sendOrderMessage(Request $request, ChatService $chatService, MessageService $messageService): JsonResponse
    {
        $payload = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'message' => ['nullable', 'required_without_all:image,audio', 'string', 'max:4000'],
            'image' => ['nullable', 'required_without_all:message,audio', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'audio' => ['nullable', 'required_without_all:message,image', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/webm,video/webm', 'max:8192'],
            'audio_duration' => ['nullable', 'integer', 'min:1'],
        ]);

        $order = Order::query()->findOrFail($payload['order_id']);
        $this->authorizeOrderParticipant($order, $request);

        try {
            $conversation = $chatService->forOrder($order, 'customer_driver');
            $chatService->assertWritable($conversation);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => $messageService->send($conversation, $request->user(), $payload),
            'conversation' => $conversation,
        ], 201);
    }

    public function typing(ChatConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request);
        $this->broadcastSafely(new TypingIndicator($conversation, $request->user()->id, $request->boolean('typing')));

        return response()->json(['data' => ['typing' => $request->boolean('typing')]]);
    }

    public function read(ChatConversation $conversation, Request $request, MessageService $messageService): JsonResponse
    {
        $this->authorizeParticipant($conversation, $request);

        return response()->json(['data' => ['updated' => $messageService->markRead($conversation, $request->user())]]);
    }

    public function rateOperator(ChatConversation $conversation, Request $request): JsonResponse
    {
        abort_unless((int) $conversation->customer_id === (int) $request->user()->id, 403);
        abort_unless($conversation->type === 'customer_operator', 422, 'Rating hanya untuk chat operator.');

        $payload = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $conversation->forceFill([
            'operator_rating' => $payload['rating'],
            'operator_rating_comment' => $payload['comment'] ?? null,
            'operator_rated_at' => now(),
            'rating_requested_at' => $conversation->rating_requested_at ?? now(),
        ])->save();

        return response()->json(['data' => $this->conversationPayload($conversation->fresh())]);
    }

    private function authorizeParticipant(ChatConversation $conversation, Request $request): void
    {
        $conversation->loadMissing(['order', 'customer', 'driver']);
        $user = $request->user();
        $role = $user->role->value ?? $user->role;

        if (in_array($role, ['admin', 'gm'], true)) {
            return;
        }

        if (in_array($role, ['operator', 'eksekutor', 'manager', 'spv'], true)) {
            abort_unless($this->staffCanAccessConversation($conversation, $user), 403);

            return;
        }

        abort_unless(
            in_array((int) $user->id, array_filter([
                $conversation->customer_id,
                $conversation->driver_id,
                $conversation->operator_id,
            ]), true),
            403,
        );
    }

    private function authorizeOrderParticipant(Order $order, Request $request): void
    {
        $order->loadMissing('driver');
        $user = $request->user();
        $role = $user->role->value ?? $user->role;

        if (in_array($role, ['admin', 'gm'], true)) {
            return;
        }

        if (in_array($role, ['operator', 'eksekutor', 'manager', 'spv'], true)) {
            abort_unless($this->staffCanAccessBranch($order->branch_id, $user), 403);

            return;
        }

        abort_unless(
            (int) $order->user_id === (int) $user->id
            || (int) optional($order->driver)->user_id === (int) $user->id,
            403,
        );
    }

    private function staffCanAccessConversation(ChatConversation $conversation, User $user): bool
    {
        if ((int) $conversation->operator_id === (int) $user->id) {
            return true;
        }

        $branchId = $conversation->branch_id
            ?? $conversation->order?->branch_id
            ?? $conversation->customer?->branch_id
            ?? $conversation->driver?->branch_id;

        return $this->staffCanAccessBranch($branchId, $user);
    }

    private function staffCanAccessBranch(?int $branchId, User $user): bool
    {
        if ($branchId === null) {
            return false;
        }

        return $user->branch_id !== null && (int) $user->branch_id === (int) $branchId;
    }

    private function broadcastSafely(object $event): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Chat broadcast failed', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function conversationPayload(ChatConversation $conversation): array
    {
        $slaService = app(SLAService::class);
        $conversation = $slaService->enforceUnansweredOperatorChat($conversation);

        $ratingDue = $conversation->type === 'customer_operator'
            && ! $conversation->operator_rating
            && (
                $conversation->status === 'closed'
                || ($conversation->last_customer_message_at && ! $conversation->first_operator_response_at && $conversation->last_customer_message_at->lte(now()->subSeconds($slaService->feedbackSeconds())))
            );

        if ($ratingDue && ! $conversation->rating_requested_at) {
            $conversation->forceFill(['rating_requested_at' => now()])->save();
        }

        return [
            'id' => $conversation->id,
            'order_id' => $conversation->order_id,
            'type' => $conversation->type,
            'status' => $conversation->status,
            'operator_id' => $conversation->operator_id,
            'operator_name' => $conversation->operator?->name,
            'operator_role' => $conversation->operator?->role?->value,
            'operator_rating' => $conversation->operator_rating,
            'rating_requested' => $ratingDue,
            'rating_requested_at' => $conversation->rating_requested_at?->toIso8601String(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'sla_status' => $conversation->sla_status,
        ];
    }
}
