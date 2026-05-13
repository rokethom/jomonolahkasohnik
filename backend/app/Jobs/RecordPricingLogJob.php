<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PricingLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordPricingLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly array $response, private readonly array $coordinates)
    {
    }

    public function handle(): void
    {
        PricingLog::query()->create([
            ...$this->coordinates,
            'branch_id' => $this->response['branch_id'] ?? null,
            'area_id' => $this->response['area_id'] ?? null,
            'pricing_ring_id' => $this->response['pricing_ring_id'] ?? null,
            'distance_km' => $this->response['distance_km'],
            'calculated_price' => $this->response['price'],
            'pricing_formula' => $this->response['pricing_formula'],
            'response_payload' => $this->response,
        ]);
    }
}
