<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LivePriceReview;
use App\Services\JojoBotService;
use App\Services\LivePriceReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JojoBotController extends Controller
{
    public function preview(Request $request, JojoBotService $jojoBot, LivePriceReviewService $liveReviews): JsonResponse
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
            $review = $liveReviews->createFromPreview($user, $data['raw_text'], $preview);
            if ($review) {
                $preview = $liveReviews->attachToPreview($preview, $review);
            }
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

    public function livePriceReviewStatus(string $token, Request $request, LivePriceReviewService $liveReviews): JsonResponse
    {
        $review = LivePriceReview::query()
            ->with(['customer.branch', 'branch', 'reviewer'])
            ->where('token', $token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'data' => $liveReviews->payload($review),
        ]);
    }

    public function cancelLivePriceReview(string $token, Request $request, LivePriceReviewService $liveReviews): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $review = LivePriceReview::query()
            ->with(['customer.branch', 'branch', 'reviewer'])
            ->where('token', $token)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $review = $liveReviews->cancelByCustomer($review, $request->user(), $payload['reason'] ?? null);

        return response()->json([
            'message' => $review->correction_reason,
            'data' => $liveReviews->payload($review),
        ]);
    }
}
