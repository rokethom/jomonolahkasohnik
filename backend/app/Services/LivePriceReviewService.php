<?php

namespace App\Services;

use App\Jobs\ProcessLivePriceReviewLearningJob;
use App\Models\Branch;
use App\Models\LivePriceReview;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LivePriceReviewService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('live_price_review_enabled', false);
    }

    public function delaySeconds(): int
    {
        return max(5, min(10, $this->settings->int('live_price_review_delay_seconds', 5)));
    }

    public function createFromPreview(User $user, string $rawText, array $preview): ?LivePriceReview
    {
        if (! $this->enabled() || ! Schema::hasTable('live_price_reviews')) {
            return null;
        }

        $payload = $preview['order_payload'] ?? null;
        $quote = $preview['quote'] ?? null;
        if (! is_array($payload) || ! is_array($quote)) {
            return $this->createFallbackReview($user, $rawText, $preview);
        }

        return LivePriceReview::query()->create([
            'token' => (string) Str::uuid(),
            'user_id' => $user->id,
            'branch_id' => $payload['branch_id'] ?? $user->branch_id,
            'service_type' => $payload['service_type'] ?? ($preview['service_type'] ?? $preview['selected_service'] ?? null),
            'status' => LivePriceReview::STATUS_PENDING,
            'raw_text' => $rawText,
            'parsed' => $preview['parsed'] ?? [],
            'order_payload' => $payload,
            'quote' => $quote,
            'system_price' => (int) ($quote['tarif'] ?? $quote['price'] ?? 0),
            'system_service_fee' => (int) ($quote['service_fee'] ?? $quote['service_charge'] ?? 0),
            'system_total_price' => (int) ($quote['total_price'] ?? $quote['final_price'] ?? 0),
            'expires_at' => now()->addMinutes(15),
        ])->load(['customer.branch', 'branch', 'reviewer']);
    }

    private function createFallbackReview(User $user, string $rawText, array $preview): LivePriceReview
    {
        $branch = $this->branch($user);
        $serviceType = (string) ($preview['service_type'] ?? $preview['selected_service'] ?? $this->inferServiceType($rawText));
        $parsed = is_array($preview['parsed'] ?? null) ? $preview['parsed'] : [];
        $pickupAddress = (string) ($parsed['store_location'] ?? $parsed['pickup_address'] ?? $branch?->name ?? 'Belum terbaca - cek raw text');
        $destinationAddress = (string) ($parsed['destination_address'] ?? $parsed['address'] ?? $user->address ?? 'Belum terbaca - cek raw text');

        $payload = [
            'service_type' => $serviceType,
            'pickup_address' => $pickupAddress,
            'pickup_lat' => (float) ($branch?->latitude ?: $user->lat ?: -7.7063),
            'pickup_lng' => (float) ($branch?->longitude ?: $user->lng ?: 114.0098),
            'destination_address' => $destinationAddress,
            'destination_lat' => (float) ($branch?->latitude ?: $user->lat ?: -7.7063),
            'destination_lng' => (float) ($branch?->longitude ?: $user->lng ?: 114.0098),
            'branch_id' => $branch?->id ?? $user->branch_id,
            'stops' => 1,
            'destination_text' => $destinationAddress,
            'notes' => trim("Parser otomatis belum yakin. Operator wajib cek raw text.\n".$rawText),
            'service_payload' => [
                'source' => 'live_price_review_fallback',
                'parser_needs_human_review' => true,
                'raw_text' => $rawText,
            ],
            'items' => [],
            'points' => [],
        ];

        $quote = [
            'tarif' => 0,
            'price' => 0,
            'base_price' => 0,
            'service_fee' => 0,
            'service_charge' => 0,
            'extra_charge' => 0,
            'subtotal' => 0,
            'final_price' => 0,
            'total_price' => 0,
            'pricing_unresolved' => true,
            'live_price_review_fallback' => true,
        ];

        return LivePriceReview::query()->create([
            'token' => (string) Str::uuid(),
            'user_id' => $user->id,
            'branch_id' => $payload['branch_id'],
            'service_type' => $serviceType,
            'status' => LivePriceReview::STATUS_PENDING,
            'raw_text' => $rawText,
            'parsed' => [
                ...$parsed,
                'parser_needs_human_review' => true,
                'message' => $preview['message'] ?? $preview['reply'] ?? null,
            ],
            'order_payload' => $payload,
            'quote' => $quote,
            'system_price' => 0,
            'system_service_fee' => 0,
            'system_total_price' => 0,
            'expires_at' => now()->addMinutes(15),
        ])->load(['customer.branch', 'branch', 'reviewer']);
    }

    public function attachToPreview(array $preview, LivePriceReview $review): array
    {
        $preview['live_price_review'] = $this->payload($review, exposeApprovedOrder: false);
        $preview['message'] = $this->pendingMessage();
        $preview['reply'] = $this->pendingMessage();

        return $preview;
    }

    public function approve(LivePriceReview $review, User $actor, array $payload): LivePriceReview
    {
        $quote = $review->quote ?? [];
        $orderPayload = $review->order_payload ?? [];
        $price = max(0, (int) ($payload['price'] ?? $review->system_price));
        $serviceFee = max(0, (int) ($payload['service_fee'] ?? $review->system_service_fee));
        $extraCharge = (int) ($payload['extra_charge'] ?? ($quote['extra_charge'] ?? 0));
        $total = max(0, $price + $serviceFee + $extraCharge);

        $quote['tarif'] = $price;
        $quote['price'] = $price;
        $quote['base_price'] = $price;
        $quote['service_fee'] = $serviceFee;
        $quote['service_charge'] = $serviceFee;
        $quote['extra_charge'] = $extraCharge;
        $quote['subtotal'] = $price + $serviceFee + $extraCharge;
        $quote['final_price'] = $total;
        $quote['total_price'] = $total;
        $quote['live_price_review'] = true;
        $quote['live_price_review_token'] = $review->token;
        $quote['system_price'] = $review->system_price;
        $quote['price_delta'] = $price - $review->system_price;

        $orderPayload['live_price_review_token'] = $review->token;
        $orderPayload['service_payload'] = [
            ...(is_array($orderPayload['service_payload'] ?? null) ? $orderPayload['service_payload'] : []),
            'live_price_review_token' => $review->token,
            'live_price_reviewed_by' => $actor->name,
        ];

        $review->forceFill([
            'status' => LivePriceReview::STATUS_APPROVED,
            'reviewed_by' => $actor->id,
            'order_payload' => $orderPayload,
            'quote' => $quote,
            'corrected_price' => $price,
            'corrected_service_fee' => $serviceFee,
            'corrected_extra_charge' => $extraCharge,
            'corrected_total_price' => $total,
            'correction_reason' => trim((string) ($payload['reason'] ?? 'Harga dikonfirmasi live oleh operator.')),
            'confirmation_available_at' => now()->addSeconds($this->delaySeconds()),
            'reviewed_at' => now(),
        ])->save();

        ProcessLivePriceReviewLearningJob::dispatch($review->id, 'approved');

        return $review->fresh(['customer.branch', 'branch', 'reviewer']);
    }

    public function reject(LivePriceReview $review, User $actor, ?string $reason = null): LivePriceReview
    {
        $review->forceFill([
            'status' => LivePriceReview::STATUS_REJECTED,
            'reviewed_by' => $actor->id,
            'correction_reason' => $reason ?: 'Harga/order perlu dicek ulang.',
            'reviewed_at' => now(),
        ])->save();

        return $review->fresh(['customer.branch', 'branch', 'reviewer']);
    }

    public function approvedReviewForOrder(User $user, array $payload): ?LivePriceReview
    {
        if (! Schema::hasTable('live_price_reviews')) {
            return null;
        }

        $token = (string) ($payload['live_price_review_token'] ?? data_get($payload, 'service_payload.live_price_review_token', ''));
        if ($token === '') {
            return null;
        }

        $review = LivePriceReview::query()
            ->where('token', $token)
            ->where('user_id', $user->id)
            ->where('status', LivePriceReview::STATUS_APPROVED)
            ->whereNull('consumed_at')
            ->first();

        if (! $review) {
            throw ValidationException::withMessages([
                'live_price_review_token' => 'Harga belum dikonfirmasi operator.',
            ]);
        }

        if ($review->confirmation_available_at && $review->confirmation_available_at->isFuture()) {
            throw ValidationException::withMessages([
                'live_price_review_token' => 'Tunggu sebentar, harga baru sedang disiapkan.',
            ]);
        }

        return $review;
    }

    public function applyApprovedPricing(array $pricing, LivePriceReview $review): array
    {
        $price = (int) ($review->corrected_price ?? $review->system_price);
        $serviceFee = (int) ($review->corrected_service_fee ?? $review->system_service_fee);
        $extra = (int) $review->corrected_extra_charge;
        $total = max(0, $price + $serviceFee + $extra);

        return [
            ...$pricing,
            'tarif' => $price,
            'price' => $price,
            'base_price' => $price,
            'service_fee' => $serviceFee,
            'service_charge' => $serviceFee,
            'extra_charge' => $extra,
            'subtotal' => $total,
            'final_price' => $total,
            'total_price' => $total,
            'live_price_review_id' => $review->id,
            'live_price_review_token' => $review->token,
            'live_price_reviewed_by' => $review->reviewer?->name,
            'system_price_before_live_review' => $review->system_price,
            'live_price_delta' => $price - $review->system_price,
        ];
    }

    public function markConsumed(LivePriceReview $review, Order $order): void
    {
        $review->forceFill([
            'status' => LivePriceReview::STATUS_CONSUMED,
            'order_id' => $order->id,
            'consumed_at' => now(),
        ])->save();

        ProcessLivePriceReviewLearningJob::dispatch($review->id, 'consumed');
    }

    public function payload(LivePriceReview $review, bool $exposeApprovedOrder = true): array
    {
        $canConfirm = $review->status === LivePriceReview::STATUS_APPROVED
            && ($review->confirmation_available_at === null || $review->confirmation_available_at->lte(now()));

        return [
            'id' => $review->id,
            'token' => $review->token,
            'status' => $review->status,
            'service_type' => $review->service_type,
            'customer' => $review->customer?->name,
            'branch' => $review->branch?->name,
            'raw_text' => $review->raw_text,
            'parsed' => $review->parsed,
            'system_price' => $review->system_price,
            'system_service_fee' => $review->system_service_fee,
            'system_total_price' => $review->system_total_price,
            'corrected_price' => $review->corrected_price,
            'corrected_service_fee' => $review->corrected_service_fee,
            'corrected_extra_charge' => $review->corrected_extra_charge,
            'corrected_total_price' => $review->corrected_total_price,
            'correction_reason' => $review->correction_reason,
            'reviewed_by' => $review->reviewer?->name,
            'order_id' => $review->order_id,
            'order_code' => $review->order?->order_code,
            'confirmation_available_at' => $review->confirmation_available_at?->toIso8601String(),
            'can_confirm' => $canConfirm,
            'order_payload' => $exposeApprovedOrder && $review->status === LivePriceReview::STATUS_APPROVED ? $review->order_payload : null,
            'quote' => $exposeApprovedOrder && $review->status === LivePriceReview::STATUS_APPROVED ? $review->quote : null,
            'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            'consumed_at' => $review->consumed_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    private function pendingMessage(): string
    {
        return "Harga sedang dicek operator.\nMohon tunggu sebentar, tombol konfirmasi akan aktif setelah harga dikonfirmasi.";
    }

    private function branch(User $user): ?Branch
    {
        if ($user->branch_id) {
            $branchId = Branch::resolveOperationalAreaId((int) $user->branch_id, (float) $user->lat ?: null, (float) $user->lng ?: null)
                ?? (int) $user->branch_id;

            return Branch::query()->find($branchId);
        }

        return Branch::query()->operationalAreas()->whereNotNull('latitude')->whereNotNull('longitude')->first();
    }

    private function inferServiceType(string $rawText): string
    {
        $text = str($rawText)->lower()->squish()->toString();

        if (preg_match('/\b(?:ojek|motorbike|motorcycle|ride|pickup|pick up|jemput)\b/u', $text) === 1) {
            return 'ojek';
        }

        if (preg_match('/\b(?:courier|kurir|send package|parcel|document|dokumen|paket)\b/u', $text) === 1) {
            return 'kurir';
        }

        if (preg_match('/\b(?:car|mobil|joker mobil|citycar)\b/u', $text) === 1) {
            return 'joker_mobil';
        }

        return 'delivery';
    }
}
