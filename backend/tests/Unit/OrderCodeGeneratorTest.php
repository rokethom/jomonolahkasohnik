<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Services\OrderCodeGenerator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderCodeGeneratorTest extends TestCase
{
    public function test_it_generates_structured_order_code(): void
    {
        Carbon::setTestNow(Carbon::create(2025, 3, 4, 14, 30));

        $branch = new Branch([
            'name' => 'Situbondo',
            'area' => 'Asembagus',
        ]);

        $code = app(OrderCodeGenerator::class)->generate('BL', $branch);

        $this->assertMatchesRegularExpression('/^BL-ASE-25030414-\d{4}$/', $code);

        Carbon::setTestNow();
    }
}
