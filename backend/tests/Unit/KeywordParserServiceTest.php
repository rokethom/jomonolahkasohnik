<?php

namespace Tests\Unit;

use App\Models\KeywordParser;
use App\Models\User;
use App\Services\JojoBotService;
use App\Services\KeywordParserService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KeywordParserServiceTest extends TestCase
{
    public function test_detect_reads_active_keyword_by_priority(): void
    {
        KeywordParser::query()->updateOrCreate(
            ['keyword' => 'travel'],
            [
                'service_type' => 'TV',
                'response_template' => 'Silakan pilih layanan Travel.',
                'form_schema' => [
                    'fields' => [
                        ['label' => 'Alamat Jemput', 'name' => 'pickup', 'type' => 'text', 'required' => true],
                    ],
                ],
                'parser_type' => 'simple',
                'is_active' => true,
                'priority' => 50,
            ],
        );

        $detected = app(KeywordParserService::class)->detect('Saya mau travel');

        $this->assertNotNull($detected);
        $this->assertSame('travel', $detected['keyword']);
        $this->assertSame('TV', $detected['service_type']);
        $this->assertSame('simple', $detected['parser_mode']);
        $this->assertSame('Silakan pilih layanan Travel.', $detected['response']);
        $this->assertSame('pickup', $detected['form_schema']['fields'][0]['name']);
    }

    public function test_jojobot_uses_simple_keyword_response_without_breaking_fallback(): void
    {
        KeywordParser::query()->updateOrCreate(
            ['keyword' => 'travel'],
            [
                'service_type' => 'TV',
                'response_template' => "Halo! Anda memilih layanan Travel.\nSilakan isi detail perjalanan Anda.",
                'parser_type' => 'simple',
                'is_active' => true,
                'priority' => 100,
            ],
        );

        $user = new User([
            'id' => 10,
            'name' => 'Customer',
            'phone' => '0800',
            'address' => 'Alamat',
            'role' => 'customer',
        ]);

        $preview = app(JojoBotService::class)->preview($user, 'travel');

        $this->assertSame('service_selected', $preview['intent']);
        $this->assertSame('travel', $preview['selected_service']);
        $this->assertSame("Halo! Anda memilih layanan Travel.\nSilakan isi detail perjalanan Anda.", $preview['reply']);
        $this->assertNull($preview['order_payload']);
    }

    public function test_detect_supports_comma_separated_keywords(): void
    {
        KeywordParser::query()->updateOrCreate(
            ['keyword' => 'belanja, pasar, belikan'],
            [
                'service_type' => 'BL',
                'response_template' => 'Silakan isi detail belanja.',
                'parser_type' => 'simple',
                'is_active' => true,
                'priority' => 10000,
            ],
        );
        Cache::forget(KeywordParserService::CACHE_KEY);

        $detected = app(KeywordParserService::class)->detect('tolong belikan sayur di pasar');

        $this->assertNotNull($detected);
        $this->assertSame('belanja, pasar, belikan', $detected['keyword']);
        $this->assertSame('BL', $detected['service_type']);
    }

    public function test_keyword_form_submission_can_become_order_preview(): void
    {
        KeywordParser::query()->updateOrCreate(
            ['keyword' => 'belanja, pasar, belikan'],
            [
                'service_type' => 'BL',
                'response_template' => 'Silakan isi detail belanja.',
                'parser_type' => 'simple',
                'is_active' => true,
                'priority' => 200,
            ],
        );

        $user = new User([
            'id' => 10,
            'name' => 'Customer',
            'phone' => '0800',
            'address' => 'Alamat',
            'role' => 'customer',
        ]);

        $preview = app(JojoBotService::class)->preview($user, "Layanan: belanja\nAlamat Antar: Rumah saya\nLokasi Pembelian: Pasar kota\nCatatan: beli sayur");

        $this->assertSame('order_preview', $preview['intent']);
        $this->assertNotNull($preview['order_payload']);
        $this->assertNull($preview['form_schema']);
    }

    public function test_keyword_belanja_form_uses_base_fare_when_destination_is_missing(): void
    {
        KeywordParser::query()->updateOrCreate(
            ['keyword' => 'belanja, pasar, belikan'],
            [
                'service_type' => 'BL',
                'response_template' => 'Silakan isi detail belanja.',
                'form_schema' => [
                    'fields' => [
                        ['label' => 'Lokasi toko', 'name' => 'store', 'type' => 'text', 'required' => true],
                        ['label' => 'List barang', 'name' => 'items', 'type' => 'textarea', 'required' => true],
                        ['label' => 'Catatan', 'name' => 'notes', 'type' => 'textarea'],
                    ],
                ],
                'parser_type' => 'simple',
                'is_active' => true,
                'priority' => 200,
            ],
        );

        $user = new User([
            'id' => 10,
            'name' => 'Customer',
            'phone' => '0800',
            'address' => 'Alamat rumah customer',
            'role' => 'customer',
        ]);

        $preview = app(JojoBotService::class)->preview($user, "Layanan: belanja\nLokasi toko: Pasar kota\nList barang: beli sayur\nCatatan: cepat");

        $this->assertSame('order_preview', $preview['intent']);
        $this->assertNotNull($preview['order_payload']);
        $this->assertNull($preview['form_schema']);
        $this->assertSame(0.0, $preview['quote']['distance']);
        $this->assertSame(6000, $preview['quote']['tarif']);
        $this->assertSame('Alamat rumah customer', $preview['order_payload']['destination_address']);
    }

    public function test_structured_form_layanan_oj_keeps_ojek_service(): void
    {
        $user = new User([
            'id' => 11,
            'name' => 'Customer',
            'phone' => '0800',
            'address' => 'Alamat rumah',
            'role' => 'customer',
        ]);

        $preview = app(JojoBotService::class)->preview($user, "Layanan: OJ\nAlamat Jemput: Rumah\nAlamat Antar: Alun alun\nCatatan: cepat");

        $this->assertSame('order_preview', $preview['intent']);
        $this->assertSame('ojek', $preview['order_payload']['service_type']);
        $this->assertSame('ojek', $preview['selected_service']);
    }
}
