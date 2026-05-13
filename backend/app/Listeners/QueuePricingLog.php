<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PricingCalculated;
use App\Jobs\RecordPricingLogJob;

class QueuePricingLog
{
    public function handle(PricingCalculated $event): void
    {
        RecordPricingLogJob::dispatch($event->result->toArray(), [
            'pickup_latitude' => $event->result->request->pickupLatitude,
            'pickup_longitude' => $event->result->request->pickupLongitude,
            'destination_latitude' => $event->result->request->destinationLatitude,
            'destination_longitude' => $event->result->request->destinationLongitude,
        ]);
    }
}
