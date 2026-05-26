<?php

namespace App\Events;

use App\Models\LivePriceReview;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LivePriceReviewUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LivePriceReview $review)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->review->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'live-price-review.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'token' => $this->review->token,
            'status' => $this->review->status,
            'confirmation_available_at' => $this->review->confirmation_available_at?->toIso8601String(),
            'updated_at' => $this->review->updated_at?->toIso8601String(),
        ];
    }
}
