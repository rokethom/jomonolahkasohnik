<?php

namespace App\Jobs;

use App\Services\OrderService;
use Illuminate\Foundation\Bus\Dispatchable;

class CancelExpiredOrdersJob
{
    use Dispatchable;

    public function handle(OrderService $orders): void
    {
        $orders->cancelExpiredCreatedOrders();
    }
}
