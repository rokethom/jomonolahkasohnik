<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function publicSettings(SettingService $settings)
    {
        return response()->json([
            'data' => $settings->publicSettings(),
        ]);
    }

    public function manifest(Request $request, string $surface, SettingService $settings): JsonResponse
    {
        abort_unless(in_array($surface, ['customer', 'driver', 'admin'], true), 404);

        $requestedOrigin = rtrim((string) $request->query('origin', ''), '/');
        $parts = filter_var($requestedOrigin, FILTER_VALIDATE_URL) ? parse_url($requestedOrigin) : null;
        $origin = is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && filled($parts['host'] ?? null)
            ? ($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''))
            : rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return response()
            ->json($settings->pwaManifest($surface, $origin))
            ->header('Cache-Control', 'public, max-age=300');
    }
}
