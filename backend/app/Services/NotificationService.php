<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\User;

class NotificationService
{
    public function __construct(private readonly PushNotificationService $push)
    {
    }

    public function sendToUser(?User $user, string $title, string $message, array $data = []): void
    {
        if (! $user) {
            return;
        }

        $tokens = $user->deviceTokens()
            ->where('is_active', true)
            ->latest('last_seen_at')
            ->get();

        foreach ($tokens as $deviceToken) {
            $result = $this->push->send($deviceToken->token, $title, $message, $data);

            $responseText = json_encode($result['response'] ?? $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! ($result['success'] ?? false) && preg_match('/UNREGISTERED|NotRegistered|InvalidRegistration|INVALID_ARGUMENT|registration-token-not-registered|not a valid FCM registration token/i', $responseText)) {
                $deviceToken->update(['is_active' => false]);
            }
        }

        Log::info('User notification queued', [
            'user_id' => $user->id,
            'tokens' => $tokens->count(),
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
