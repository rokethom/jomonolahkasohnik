<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Services\PricingParser;
use App\Services\ServiceParserService;
use Tests\TestCase;

class DriverRequestParsingTest extends TestCase
{
    public function test_price_parser_supports_k_format(): void
    {
        $parser = app(PricingParser::class);

        $this->assertSame(7000, $parser->parse('jasa/js 7k'));
        $this->assertSame(15500, $parser->parse('jasa 15.5k'));
    }

    public function test_service_parser_extracts_service_and_price(): void
    {
        Service::query()->updateOrCreate(
            ['code' => 'DO'],
            ['name' => 'Delivery', 'is_active' => true, 'form_schema' => ['fields' => []]],
        );

        $parsed = app(ServiceParserService::class)->parse("Do\npiscok dawuhan\nke Ayani\njasa/js 7k");

        $this->assertSame('DO', $parsed['service_code']);
        $this->assertSame(7000, $parsed['price']);
        $this->assertSame('piscok dawuhan', $parsed['pickup_address']);
        $this->assertSame('Ayani', $parsed['destination_address']);
    }
}
