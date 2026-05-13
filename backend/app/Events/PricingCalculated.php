<?php

declare(strict_types=1);

namespace App\Events;

use App\DTOs\Pricing\PricingResultData;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PricingCalculated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PricingResultData $result)
    {
    }
}
