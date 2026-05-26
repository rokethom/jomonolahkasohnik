<?php

namespace Tests\Unit;

use App\Support\AnalyticsPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_builds_presets_and_the_previous_comparison_window(): void
    {
        Carbon::setTestNow('2026-05-25 12:00:00');

        $period = AnalyticsPeriod::fromFilters(['period' => '7_days']);

        $this->assertSame('2026-05-19', $period->from->toDateString());
        $this->assertSame('2026-05-25', $period->to->toDateString());
        $this->assertSame('2026-05-12', $period->previous()->from->toDateString());
        $this->assertSame('2026-05-18', $period->previous()->to->toDateString());
    }

    public function test_it_normalizes_a_reversed_custom_range(): void
    {
        $period = AnalyticsPeriod::fromFilters([
            'period' => 'custom',
            'from' => '2026-05-25',
            'to' => '2026-05-20',
        ]);

        $this->assertSame('2026-05-20', $period->from->toDateString());
        $this->assertSame('2026-05-25', $period->to->toDateString());
    }
}
