<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushNotificationService
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function send(string $token, string $title, string $message, array $data = []): array
    {
        if (filled($this->settings->get('fcm_service_account_json'))) {
            return $this->sendV1($token, $title, $message, $data);
        }

        $serverKey = $this->settings->get('fcm_server_key');

        if (! filled($serverKey)) {
            return [
                'success' => false,
                'message' => 'FCM server key belum aktif di System Settings.',
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'key='.$serverKey,
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $message,
            ],
            'data' => $data,
        ]);

        Log::info('FCM test push response', [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]);

        if ($response->status() === 404) {
            return [
                'success' => false,
                'status' => 404,
                'message' => 'Endpoint FCM legacy tidak tersedia. Isi Service Account JSON agar sistem memakai FCM HTTP v1.',
                'response' => $response->json() ?? $response->body(),
            ];
        }

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ];
    }

    private function sendV1(string $token, string $title, string $message, array $data = []): array
    {
        $serviceAccount = $this->serviceAccount();
        if (! $serviceAccount) {
            return [
                'success' => false,
                'message' => 'FCM service account JSON tidak valid.',
            ];
        }

        $accessToken = $this->accessToken($serviceAccount);
        if (! $accessToken) {
            return [
                'success' => false,
                'message' => 'Gagal membuat OAuth token untuk FCM HTTP v1.',
            ];
        }

        $projectId = $serviceAccount['project_id'] ?? null;
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $message,
                    ],
                    'data' => collect($data)
                        ->map(fn ($value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->all(),
                    'webpush' => [
                        'notification' => [
                            'title' => $title,
                            'body' => $message,
                            'icon' => '/logo.png',
                        ],
                    ],
                ],
            ]);

        Log::info('FCM v1 push response', [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]);

        return [
            'success' => $response->successful(),
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
        ];
    }

    private function serviceAccount(): ?array
    {
        $json = $this->settings->get('fcm_service_account_json');
        $decoded = filled($json) ? json_decode((string) $json, true) : null;

        return is_array($decoded) && filled($decoded['client_email'] ?? null) && filled($decoded['private_key'] ?? null) && filled($decoded['project_id'] ?? null)
            ? $decoded
            : null;
    }

    private function accessToken(array $serviceAccount): ?string
    {
        try {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode([
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $signature = '';
            openssl_sign("{$header}.{$claims}", $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "{$header}.{$claims}.{$this->base64Url($signature)}";

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->successful() ? (string) data_get($response->json(), 'access_token') : null;
        } catch (Throwable $exception) {
            Log::warning('fcm.oauth_failed', ['message' => $exception->getMessage()]);

            return null;
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
