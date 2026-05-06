<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RatingService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function rate(Order $order, User $customer, int $rating, ?string $comment = null): Rating
    {
        if ((int) $order->user_id !== (int) $customer->id) {
            throw ValidationException::withMessages(['order' => 'Order bukan milik customer ini.']);
        }

        if ($order->status !== OrderStatus::Completed || ! $order->driver_id) {
            throw ValidationException::withMessages(['order' => 'Rating hanya bisa diberikan untuk order selesai.']);
        }

        $ratingRow = Rating::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'customer_id' => $customer->id,
                'driver_id' => $order->driver_id,
                'rating' => max(1, min(5, $rating)),
                'comment' => $comment,
            ],
        );

        $order->loadMissing('driver.user');
        $this->notifications->sendToUser(
            $order->driver?->user,
            'Rating customer masuk',
            "Customer memberi rating {$ratingRow->rating} bintang untuk order {$order->order_code}.",
            [
                'type' => 'driver_rating_received',
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'rating' => $ratingRow->rating,
            ],
        );

        return $ratingRow;
    }

    public function driverSummary(int $driverId): array
    {
        $query = Rating::query()->where('driver_id', $driverId);

        return [
            'average' => round((float) $query->avg('rating'), 2),
            'count' => (clone $query)->count(),
            'histogram' => collect(range(1, 5))->mapWithKeys(fn (int $score): array => [
                $score => (clone $query)->where('rating', $score)->count(),
            ])->all(),
        ];
    }
}
