<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class MessageService
{
    public function __construct(
        private readonly SLAService $slaService,
        private readonly NotificationService $notifications,
    )
    {
    }

    public function send(ChatConversation $conversation, User $sender, array $payload): ChatMessage
    {
        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'sender_type' => $payload['sender_type'] ?? ($sender->role->value ?? $sender->role ?? 'user'),
            'message' => $payload['message'] ?? '',
            'image_url' => $this->storeFile($payload['image'] ?? null, 'chat/images'),
            'audio_url' => $this->storeFile($payload['audio'] ?? null, 'chat/audio'),
            'audio_duration' => $payload['audio_duration'] ?? null,
        ]);

        $role = $sender->role->value ?? $sender->role;
        if ($role === 'customer') {
            $this->slaService->start($conversation, $message);
        }

        if (in_array($role, ['admin', 'operator', 'eksekutor', 'gm', 'manager', 'spv'], true)) {
            $this->slaService->resolve($conversation);
        }

        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Chat broadcast failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $conversation->loadMissing(['customer', 'driver', 'operator']);
        foreach ([$conversation->customer, $conversation->driver, $conversation->operator] as $recipient) {
            if (! $recipient || (int) $recipient->id === (int) $sender->id) {
                continue;
            }

            $this->notifications->sendToUser(
                $recipient,
                'Pesan baru JOJO',
                $message->message ?: 'Anda menerima lampiran baru.',
                [
                    'type' => 'chat_message',
                    'conversation_id' => $conversation->id,
                    'order_id' => $conversation->order_id,
                    'message_id' => $message->id,
                    'url' => $this->chatNotificationUrl($conversation),
                ],
            );
        }

        return $message->load('sender');
    }

    public function markRead(ChatConversation $conversation, User $reader): int
    {
        return $conversation->messages()
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'is_read' => true]);
    }

    private function storeFile(mixed $file, string $path): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        return '/storage/'.$file->store($path, 'public');
    }

    private function chatNotificationUrl(ChatConversation $conversation): string
    {
        return '/?'.http_build_query(array_filter([
            'open' => $conversation->order_id ? 'driver-chat' : 'cs-chat',
            'conversation_id' => $conversation->id,
            'order_id' => $conversation->order_id,
            'notification_type' => 'chat_message',
        ], fn ($value) => filled($value)));
    }
}
