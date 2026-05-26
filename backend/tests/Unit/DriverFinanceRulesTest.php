<?php

namespace Tests\Unit;

use App\Services\DriverFinanceService;
use App\Services\Pricing\JokerPricing;
use Tests\TestCase;

class DriverFinanceRulesTest extends TestCase
{
    public function test_bpjs_premium_is_removed_when_base_deposit_reaches_thirty_thousand(): void
    {
        $finance = app(DriverFinanceService::class);

        $this->assertSame(20000, $finance->bpjsPremiumForBaseDeposit(29999));
        $this->assertSame(0, $finance->bpjsPremiumForBaseDeposit(30000));
        $this->assertSame(0, $finance->bpjsPremiumForBaseDeposit(67400));
    }

    public function test_joker_mobil_deposit_uses_ring_one_amount_plus_ten_percent_of_remaining_jasa(): void
    {
        $pricing = app(JokerPricing::class);

        $this->assertSame(1000, $pricing->depositAmount(25000, 3));
        $this->assertSame(1500, $pricing->depositAmount(30000, 4));
        $this->assertSame(2500, $pricing->depositAmount(40000, 6));
    }
}
