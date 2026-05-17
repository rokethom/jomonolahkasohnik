<?php

namespace App\Jobs;

use App\Models\LivePriceReview;
use App\Services\AiLocationLearningService;
use App\Services\AiLogService;
use App\Services\AiParserRuleService;
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
        AiParserRuleService $parserRules,
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

            $parserRule = null;
            if (filled($review->raw_text) && is_array($review->order_payload)) {
                $parserRule = $parserRules->remember(
                    (string) $review->raw_text,
                    $this->parserMemoryPayload($review),
                    'jojo-native',
                    'live-price-review-learning',
                );
            }

            $logs->success($log, [
                'pricing_suggestion_id' => $suggestion?->id,
                'pricing_suggestion_created' => $suggestion !== null,
                'parser_rule_id' => $parserRule?->id,
                'parser_rule_saved' => $parserRule !== null,
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

    private function parserMemoryPayload(LivePriceReview $review): array
    {
        $payload = $review->order_payload ?? [];
        $servicePayload = is_array($payload['service_payload'] ?? null) ? $payload['service_payload'] : [];

        return [
            'service_type' => $review->service_type ?: ($payload['service_type'] ?? null),
            'pickup_address' => $payload['pickup_address'] ?? null,
            'destination_address' => $payload['destination_address'] ?? null,
            'store_location' => $servicePayload['store_location'] ?? $payload['pickup_address'] ?? null,
            'purchase_address' => $servicePayload['purchase_address'] ?? $servicePayload['store_location'] ?? null,
            'customer_name' => $review->customer?->name,
            'customer_phone' => $review->customer?->phone,
            'customer_address' => $review->customer?->address,
            'items' => is_array($payload['items'] ?? null) ? $payload['items'] : [],
            'passengers' => $servicePayload['passengers'] ?? null,
            'notes' => $payload['notes'] ?? $review->correction_reason,
            'missing_fields' => [],
        ];
    }
}
