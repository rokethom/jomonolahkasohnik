<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JojoBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JojoBotController extends Controller
{
    public function preview(Request $request, JojoBotService $jojoBot): JsonResponse
    {
        $data = $request->validate([
            'raw_text' => ['required', 'string', 'max:4000'],
            'device_location' => ['nullable', 'array'],
            'device_location.lat' => ['required_with:device_location', 'numeric', 'between:-90,90'],
            'device_location.lng' => ['required_with:device_location', 'numeric', 'between:-180,180'],
        ]);

        try {
            $user = $request->user();
            if (isset($data['device_location']['lat'], $data['device_location']['lng'])) {
                $user->forceFill([
                    'lat' => (float) $data['device_location']['lat'],
                    'lng' => (float) $data['device_location']['lng'],
                ]);
            }

            $preview = $jojoBot->preview($user, $data['raw_text']);
        } catch (\Throwable $exception) {
            Log::warning('jojobot.preview_failed', [
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'JOJOBOT belum berhasil menghitung pesanan. Coba ulangi sebentar lagi atau cek alamat pickup dan tujuan.',
                'form_schema' => null,
                'service_type' => null,
                'data' => [
                    'intent' => 'pricing_unavailable',
                    'reply' => 'JOJOBOT belum berhasil menghitung pesanan. Coba ulangi sebentar lagi atau cek alamat pickup dan tujuan.',
                ],
            ]);
        }

        return response()->json([
            'message' => $preview['message'] ?? $preview['reply'] ?? null,
            'form_schema' => $preview['form_schema'] ?? null,
            'service_type' => $preview['service_type'] ?? $preview['selected_service'] ?? null,
            'data' => $preview,
        ]);
    }
}
