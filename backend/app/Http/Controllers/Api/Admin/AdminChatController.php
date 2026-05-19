<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\CancelRequest;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatSticker;
use App\Models\User;
use App\Services\CancelService;
use App\Services\ChatService;
use App\Services\MessageService;
use App\Services\SLAService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminChatController extends Controller
{
    public function index(Request $request, SLAService $slaService): JsonResponse
    {
        $slaService->enforceUnansweredOperatorChats((clone $this->query($request->user())));

        return response()->json([
            'data' => $this->query($request->user())
                ->with(['customer', 'driver', 'operator', 'order', 'latestMessage'])
                ->paginate($request->integer('per_page', 30))
                ->through(fn (ChatConversation $chat): array => $this->payload($chat, $request->user())),
        ]);
    }

    public function show(ChatConversation $conversation, Request $request, SLAService $slaService): JsonResponse
    {
        $this->authorizeChat($conversation, $request->user());
        $conversation = $slaService->enforceUnansweredOperatorChat($conversation->refresh());

        return response()->json([
            'data' => [
                'chat' => $this->payload($conversation->load(['customer', 'driver', 'operator', 'order', 'latestMessage']), $request->user()),
                'messages' => $conversation->messages()
                    ->with(['sender', 'sticker'])
                    ->oldest()
                    ->get()
                    ->map(fn (ChatMessage $message): array => $this->messagePayload($message)),
                'cancel_request' => CancelRequest::query()
                    ->where('chat_conversation_id', $conversation->id)
                    ->where('status', 'pending')
                    ->latest()
                    ->first(),
            ],
        ]);
    }

    public function startDriver(User $driverUser, Request $request, ChatService $chatService): JsonResponse
    {
        $driverRole = $driverUser->role instanceof UserRole ? $driverUser->role->value : (string) $driverUser->role;

        abort_unless($driverRole === UserRole::Driver->value, 422, 'User ini bukan driver.');
        $this->authorizeDriverChat($driverUser, $request->user());

        $conversation = $chatService->startCustomerOperator($driverUser);
        $conversation->load(['customer', 'driver', 'operator', 'order', 'latestMessage']);

        return response()->json(['data' => $this->payload($conversation, $request->user())]);
    }

    public function sendMessage(Request $request, MessageService $messageService, ChatService $chatService, SLAService $slaService): JsonResponse
    {
        $payload = $request->validate([
            'chat_id' => ['required', 'exists:chat_conversations,id'],
            'message' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:4096'],
            'audio' => ['nullable', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/webm,video/webm', 'max:8192'],
            'audio_duration' => ['nullable', 'integer', 'min:1'],
            'transcription' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,txt'],
            'chat_sticker_id' => ['nullable', 'exists:chat_stickers,id'],
        ]);

        $conversation = ChatConversation::query()->findOrFail($payload['chat_id']);
        $this->authorizeChat($conversation, $request->user());
        $conversation = $slaService->enforceUnansweredOperatorChat($conversation);
        $chatService->assertWritable($conversation);

        if ($request->user()->role instanceof UserRole && $request->user()->role->isStaff() && ! $conversation->operator_id) {
            $conversation->forceFill(['operator_id' => $request->user()->id, 'status' => 'active'])->save();
            $joinedMessage = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_type' => $request->user()->role->value,
                'message' => sprintf(
                    '%s %s bergabung dalam percakapan ini.',
                    $request->user()->name,
                    $this->roleLabel($request->user()->role),
                ),
            ]);
            try {
                broadcast(new MessageSent($joinedMessage->load('sender')))->toOthers();
            } catch (\Throwable) {
                // Realtime is best-effort; polling will still sync the joined notice.
            }
        }

        $transcription = trim((string) ($payload['transcription'] ?? ''));
        $messageText = trim((string) ($payload['message'] ?? ''));
        $stickerId = isset($payload['chat_sticker_id']) ? (int) $payload['chat_sticker_id'] : null;
        if ($stickerId) {
            abort_unless(ChatSticker::query()->whereKey($stickerId)->where('is_active', true)->exists(), 422, 'Sticker tidak aktif.');
        }

        $message = $messageService->send($conversation, $request->user(), [
            ...$payload,
            'message' => trim($messageText.($transcription !== '' ? "\n\nTranskripsi: ".$transcription : '')) ?: ($stickerId ? 'Mengirim sticker' : ''),
            'sender_type' => $request->user()->role->value,
            'file_name' => $request->file('file')?->getClientOriginalName(),
            'file_mime' => $request->file('file')?->getClientMimeType(),
            'file_size' => $request->file('file')?->getSize(),
            'chat_sticker_id' => $stickerId,
            'message_type' => $stickerId ? 'sticker' : 'text',
        ]);

        return response()->json(['data' => $this->messagePayload($message)], 201);
    }

    public function close(ChatConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeChat($conversation, $request->user());
        $conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        return response()->json(['data' => $this->payload($conversation->fresh(['customer', 'driver', 'operator', 'order', 'latestMessage']), $request->user())]);
    }

    public function approveCancel(CancelRequest $cancelRequest, Request $request, CancelService $cancelService): JsonResponse
    {
        $this->authorizeCancelApprover($request->user());

        return response()->json(['data' => $cancelService->approve($cancelRequest, $request->user())]);
    }

    public function rejectCancel(CancelRequest $cancelRequest, Request $request, CancelService $cancelService): JsonResponse
    {
        $this->authorizeCancelApprover($request->user());

        return response()->json(['data' => $cancelService->reject($cancelRequest, $request->user())]);
    }

    private function query(User $actor): Builder
    {
        $query = ChatConversation::query()
            ->whereIn('type', ['customer_operator', 'driver_operator'])
            ->latest('updated_at');

        if ($actor->role === UserRole::Operator) {
            $query->where(function (Builder $query) use ($actor): void {
                $query->where('operator_id', $actor->id)
                    ->orWhereNull('operator_id')
                    ->orWhere('status', 'waiting');
            });
        }

        if ($actor->role === UserRole::Eksekutor) {
            $query->where('branch_id', $actor->branch_id)
                ->where(function (Builder $query) use ($actor): void {
                    $query->where('operator_id', $actor->id)
                        ->orWhereNull('operator_id')
                        ->orWhere('status', 'waiting');
                });
        }

        return $query;
    }

    private function authorizeChat(ChatConversation $conversation, User $actor): void
    {
        $this->authorizeOperator($actor);

        if ($actor->role === UserRole::Operator && $conversation->operator_id && (int) $conversation->operator_id !== (int) $actor->id) {
            abort(403);
        }

        if ($actor->role === UserRole::Eksekutor) {
            abort_unless((int) $conversation->branch_id === (int) $actor->branch_id, 403);

            if ($conversation->operator_id && (int) $conversation->operator_id !== (int) $actor->id) {
                abort(403);
            }
        }
    }

    private function authorizeOperator(User $actor): void
    {
        abort_unless($actor->role instanceof UserRole && $actor->role->isStaff(), 403);
    }

    private function authorizeDriverChat(User $driverUser, User $actor): void
    {
        $this->authorizeOperator($actor);

        if (in_array($actor->role, [UserRole::Admin, UserRole::GM, UserRole::Operator, UserRole::SPV], true)) {
            return;
        }

        abort_unless(
            $actor->branch_id !== null && (int) $actor->branch_id === (int) $driverUser->branch_id,
            403,
            'Driver di luar area akun ini.',
        );
    }

    private function authorizeCancelApprover(User $actor): void
    {
        abort_unless(in_array($actor->role, [UserRole::Admin, UserRole::GM, UserRole::Operator, UserRole::Eksekutor, UserRole::SPV], true), 403);
    }

    private function payload(ChatConversation $chat, User $viewer): array
    {
        return [
            'id' => $chat->id,
            'order_id' => $chat->order_id,
            'order_code' => $chat->order?->order_code,
            'type' => $chat->type,
            'customer' => $chat->customer?->name,
            'driver' => $chat->driver?->name,
            'operator' => $chat->operator?->name,
            'operator_rating' => $chat->operator_rating,
            'rating_requested_at' => $chat->rating_requested_at?->toISOString(),
            'closed_at' => $chat->closed_at?->toISOString(),
            'status' => $chat->status,
            'sla_status' => $chat->sla_status,
            'last_message' => $chat->latestMessage?->message,
            'last_customer_message_at' => $chat->last_customer_message_at?->toISOString(),
            'first_operator_response_at' => $chat->first_operator_response_at?->toISOString(),
            'unread_count' => $chat->messages()
                ->where('sender_id', '!=', $viewer->id)
                ->whereNull('read_at')
                ->count(),
            'updated_at' => $chat->updated_at?->toISOString(),
        ];
    }

    private function messagePayload(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'chat_id' => $message->chat_conversation_id,
            'sender_id' => $message->sender_id,
            'sender_type' => $message->sender_type,
            'sender_name' => $message->sender?->name,
            'message' => $message->message,
            'image_url' => $message->image_url,
            'audio_url' => $message->audio_url,
            'audio_duration' => $message->audio_duration,
            'file_url' => $message->file_url,
            'file_name' => $message->file_name,
            'file_mime' => $message->file_mime,
            'file_size' => $message->file_size,
            'message_type' => $message->message_type,
            'sticker' => $message->sticker ? [
                'id' => $message->sticker->id,
                'name' => $message->sticker->name,
                'category' => $message->sticker->category,
                'image_url' => $message->sticker->image_url,
            ] : null,
            'is_read' => $message->is_read,
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    private function roleLabel(UserRole $role): string
    {
        return match ($role) {
            UserRole::Admin => 'Admin',
            UserRole::GM => 'GM',
            UserRole::HRD => 'HRD',
            UserRole::Manager => 'Manager',
            UserRole::SPV => 'SPV',
            UserRole::Operator => 'Operator',
            UserRole::Eksekutor => 'Eksekutor',
            default => 'Manajemen',
        };
    }
}
