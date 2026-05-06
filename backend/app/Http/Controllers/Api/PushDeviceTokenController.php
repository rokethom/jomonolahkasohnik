<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushDeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', 'max:40'],
            'app' => ['nullable', 'string', 'max:40'],
        ]);

        $device = UserDeviceToken::query()->updateOrCreate(
            ['token' => $payload['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $payload['platform'] ?? 'web',
                'app' => $payload['app'] ?? null,
                'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'is_active' => true,
                'last_seen_at' => now(),
            ],
        );

        Log::info('push.device_token_registered', [
            'user_id' => $request->user()->id,
            'device_token_id' => $device->id,
            'app' => $device->app,
            'platform' => $device->platform,
            'token_prefix' => substr($device->token, 0, 18),
            'token_length' => strlen($device->token),
        ]);

        return response()->json(['data' => $device]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        UserDeviceToken::query()
            ->where('user_id', $request->user()->id)
            ->where('token', $payload['token'])
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Device token disabled']);
    }
}
