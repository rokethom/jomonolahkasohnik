<?php

namespace App\Jobs;

use App\Models\LivePriceReview;
use App\Services\AiLocationLearningService;
use App\Services\AiLogService;
use App\Services\RingPricingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessLivePriceReviewLearningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public readonly int $livePriceReviewId,
        public readonly string $event = 'consumed',
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        AiLogService $logs,
        RingPricingService $pricing,
        AiLocationLearningService $locations,
    ): void {
        $review = LivePriceReview::query()
            ->with(['customer', 'branch', 'reviewer', 'order'])
            ->find($this->livePriceReviewId);

        if (! $review) {
            return;
        }

        $log = $logs->start([
            'source' => 'live_price_review',
            'event' => $this->event,
            'queue' => 'ai',
            'user_id' => $review->user_id,
            'actor_id' => $review->reviewed_by,
            'branch_id' => $review->branch_id,
            'order_id' => $review->order_id,
            'live_price_review_id' => $review->id,
            'provider' => 'jojo-native',
            'model' => 'rule-learning',
            'input_payload' => [
                'status' => $review->status,
                'service_type' => $review->service_type,
                'raw_text' => $review->raw_text,
                'system_price' => $review->system_price,
                'corrected_price' => $review->corrected_price,
                'system_total_price' => $review->system_total_price,
                'corrected_total_price' => $review->corrected_total_price,
                'order_payload' => $review->order_payload,
                'quote' => $review->quote,
            ],
        ]);

        try {
            $suggestion = null;
            if ($review->status === LivePriceReview::STATUS_CONSUMED) {
                $suggestion = $pricing->recordLivePriceReview($review, $review->reviewer);
            }

            $locationLearning = ['created' => 0, 'updated' => 0, 'suggestions' => []];
            if (filled($review->raw_text)) {
                $locationLearning = $locations->learnFromWhatsappText(
                    (string) $review->raw_text,
                    $review->branch_id,
                    $review->order_payload['area_id'] ?? null,
                );
            }

            $logs->success($log, [
                'pricing_suggestion_id' => $suggestion?->id,
                'pricing_suggestion_created' => $suggestion !== null,
                'location_learning' => [
                    'created' => (int) ($locationLearning['created'] ?? 0),
                    'updated' => (int) ($locationLearning['updated'] ?? 0),
                    'suggestion_count' => count($locationLearning['suggestions'] ?? []),
                ],
            ], 'Live price review learning selesai.');
        } catch (Throwable $exception) {
            $logs->failed($log, $exception);

            throw $exception;
        }
    }
}
