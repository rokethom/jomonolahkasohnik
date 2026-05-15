<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\SettingService;
use App\Services\OrderParserService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderParserServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->set('ai_assistant_enabled', false);
    }

    public function test_smart_parser_reads_natural_delivery_order(): void
    {
        $user = new User([
            'id' => 91,
            'name' => 'Aisy Profile',
            'phone' => '08123456789',
            'address' => 'Jl. Mawar No 7',
            'lat' => -7.7063,
            'lng' => 114.0098,
            'branch_id' => null,
        ]);

        $parsed = app(OrderParserService::class)->parse(
            $user,
            'beli kopi listrik di depan alun alun situbondo dan kirim ke alamat saya',
        );

        $this->assertNotNull($parsed);
        $this->assertSame('DO', $parsed['service_type']);
        $this->assertSame([[
            'name' => 'kopi listrik',
            'qty' => 1,
            'quantity' => 1,
        ]], $parsed['items']);
        $this->assertSame('depan alun alun situbondo', $parsed['store_location']);
        $this->assertSame('Jl. Mawar No 7', $parsed['destination']);
        $this->assertSame([
            'name' => 'Aisy Profile',
            'phone' => '08123456789',
        ], $parsed['customer']);
        $this->assertSame('depan alun alun situbondo', $parsed['payload']['pickup_address']);
        $this->assertSame('Jl. Mawar No 7', $parsed['payload']['destination_address']);
    }

    public function test_delivery_order_plain_text_is_parsed_with_profile_data(): void
    {
        $user = new User([
            'id' => 77,
            'name' => 'Profile Name',
            'phone' => '0800000000',
            'branch_id' => null,
        ]);

        $text = "Ada pesanan Delivery Order untuk Aplikasi Joker\n\nNama : Aisy\nHp / WhatsApp : 082141899321\nAlamat : Pendopo\n\nBelikan :\na : Ice kopi Gula aren 3\nb : Cireng 2\nc : tahu walek 2\n\nAlamat pembelian :\nArea : Kopi Kita Selatan Taman / timur alun² situbondo";

        $parsed = app(OrderParserService::class)->parse($user, $text);

        $this->assertNotNull($parsed);
        $this->assertSame('DO', $parsed['service_type']);
        $this->assertSame('Profile Name', $parsed['name']);
        $this->assertSame('0800000000', $parsed['phone']);
        $this->assertSame('Kopi Kita Selatan Taman / timur alun² situbondo', $parsed['store_location']);
        $this->assertSame('Kopi Kita Selatan Taman / timur alun² situbondo', $parsed['payload']['pickup_address']);
        $this->assertSame($parsed['address'], $parsed['payload']['destination_address']);
        $this->assertSame([
            ['name' => 'Ice kopi gula aren', 'quantity' => 3],
            ['name' => 'Cireng', 'quantity' => 2],
            ['name' => 'Tahu walek', 'quantity' => 2],
        ], $parsed['items']);
    }

    public function test_manual_order_with_pesanan_and_repeated_alamat_is_parsed_as_purchase(): void
    {
        $user = new User([
            'id' => 92,
            'name' => 'Manual Customer',
            'phone' => '081252461537',
            'address' => 'Perum Panji Permai MM19',
            'branch_id' => null,
        ]);

        $text = "Nama : Vira\nNo hp : 081252461537\nAlamat : Perum Panji Permai MM19\n\nPesanan :\na. 2 porsi rendang tanpa nasi\nb.\nc.\n\nAlamat : warung padang pagi sore timur alun2 situbondo";

        $parsed = app(OrderParserService::class)->parse($user, $text);

        $this->assertNotNull($parsed);
        $this->assertSame('DO', $parsed['service_type']);
        $this->assertSame('warung padang pagi sore timur alun2 situbondo', $parsed['store_location']);
        $this->assertSame('warung padang pagi sore timur alun2 situbondo', $parsed['payload']['pickup_address']);
        $this->assertSame('Perum Panji Permai MM19', $parsed['payload']['destination_address']);
        $this->assertSame([
            ['name' => 'Rendang tanpa nasi', 'quantity' => 2],
        ], $parsed['items']);
    }

    public function test_courier_plain_text_is_parsed_with_profile_sender(): void
    {
        $user = new User([
            'id' => 88,
            'name' => 'Sender Profile',
            'phone' => '0811111111',
            'branch_id' => null,
        ]);

        $text = "Ada Pesanan Kurir untuk Aplikasi Joker\n\nNama : Abaikan\nHp / WhatsApp : 0800\nAlamat : Abaikan\n\nAntarkan barang ke\n\nNama : Budi\nHp / WhatsApp : 0822\nAlamat : Dawuhan\n\nJenis barang : Dokumen\nHarga : 12000";

        $parsed = app(OrderParserService::class)->parse($user, $text);

        $this->assertNotNull($parsed);
        $this->assertSame('kurir', $parsed['service_type']);
        $this->assertSame('Sender Profile', $parsed['name']);
        $this->assertSame('Budi', $parsed['receiver']['name']);
        $this->assertSame('Dawuhan', $parsed['receiver']['address']);
        $this->assertSame([['name' => 'Dokumen', 'quantity' => 1, 'price' => 12000]], $parsed['items']);
    }

    public function test_ojek_plain_text_is_parsed_with_profile_customer(): void
    {
        $user = new User([
            'id' => 89,
            'name' => 'Rani Profile',
            'phone' => '0833',
            'branch_id' => null,
        ]);

        $text = "Ada pesanan Ojek untuk Aplikasi Joker\n\nNama : Abaikan\nHp / WhatsApp : 0800\nAlamat Jemput : Abaikan\n\nAlamat Antar : Alun alun\nJumlah penumpang : 2\n\nCatatan : cepat";

        $parsed = app(OrderParserService::class)->parse($user, $text);

        $this->assertNotNull($parsed);
        $this->assertSame('ojek', $parsed['service_type']);
        $this->assertSame('Rani Profile', $parsed['name']);
        $this->assertSame('Alun alun', $parsed['store_location']);
        $this->assertSame(2, $parsed['passengers']);
    }

    public function test_gift_order_plain_text_is_parsed_with_profile_confirmation(): void
    {
        $user = new User([
            'id' => 90,
            'name' => 'Dina Profile',
            'phone' => '0844',
            'branch_id' => null,
        ]);

        $text = "Ada Pesanan Gift Order untuk Aplikasi Joker\n\nKonfirmasi ke\n\nNama : Abaikan\nHp / WhatsApp : 0800\n\nDiantar ke\n\nNama : Lala\nHp / WhatsApp : 0855\nAlamat : Panji\n\nBelikan :\n- Kue ulang tahun 1\n- Coklat 2\n\nAlamat pembelian : Toko Manis\nArea : Situbondo";

        $parsed = app(OrderParserService::class)->parse($user, $text);

        $this->assertNotNull($parsed);
        $this->assertSame('gift_order', $parsed['service_type']);
        $this->assertSame('Dina Profile', $parsed['name']);
        $this->assertSame('0844', $parsed['phone']);
        $this->assertSame('Lala', $parsed['receiver']['name']);
        $this->assertSame('Panji', $parsed['receiver']['address']);
        $this->assertSame('Toko Manis - Situbondo', $parsed['store_location']);
        $this->assertSame([
            ['name' => 'Kue ulang tahun', 'quantity' => 1],
            ['name' => 'Coklat', 'quantity' => 2],
        ], $parsed['items']);
    }

    public function test_ai_parser_layer_is_optional_and_falls_back_when_disabled(): void
    {
        app(SettingService::class)->set('ai_assistant_enabled', false);
        Http::fake();

        $user = new User([
            'id' => 91,
            'name' => 'Aisy Profile',
            'phone' => '08123456789',
            'address' => 'Jl. Mawar No 7',
            'branch_id' => null,
        ]);

        $parsed = app(OrderParserService::class)->parse(
            $user,
            'beli kopi listrik di depan alun alun situbondo dan kirim ke alamat saya',
        );

        $this->assertNotNull($parsed);
        $this->assertSame('DO', $parsed['service_type']);
        $this->assertArrayNotHasKey('ai_parser', $parsed);
        Http::assertNothingSent();
    }

    public function test_ai_parser_layer_can_extract_order_without_creating_order(): void
    {
        $settings = app(SettingService::class);
        $settings->set('ai_assistant_enabled', true);
        $settings->set('ai_provider', 'openai');
        $settings->set('ai_model', 'gpt-test');
        $settings->set('ai_base_url', 'https://ai.test/v1');
        $settings->set('openai_api_key', 'test-key', true);

        Http::fake([
            'ai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'service_type' => 'ojek',
                                'pickup_address' => 'Rumah',
                                'destination_address' => 'Alun alun',
                                'store_location' => null,
                                'items' => [],
                                'passengers' => 1,
                                'notes' => 'cepat',
                                'missing_fields' => [],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        $user = new User([
            'id' => 92,
            'name' => 'Aisy Profile',
            'phone' => '08123456789',
            'address' => 'Jl. Mawar No 7',
            'branch_id' => null,
        ]);

        $parsed = app(OrderParserService::class)->parse($user, 'tolong ojek dari rumah ke alun alun');

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['ai_parser']);
        $this->assertSame('ojek', $parsed['service_type']);
        $this->assertSame('ojek', $parsed['payload']['service_type']);
        $this->assertSame('Alun alun', $parsed['payload']['destination_address']);
    }

    public function test_ai_parser_keeps_purchase_address_separate_from_customer_pickup_schema(): void
    {
        $settings = app(SettingService::class);
        $settings->set('ai_assistant_enabled', true);
        $settings->set('ai_provider', 'kimi');
        $settings->set('ai_model', 'kimi-pro');
        $settings->set('ai_base_url', 'https://konektika.web.id/v1');
        $settings->set('kimi_api_key', 'test-key', true);

        Http::fake([
            'konektika.web.id/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => "```json\n".json_encode([
                                'service_type' => 'DO',
                                'pickup_address' => null,
                                'destination_address' => 'alamat profile',
                                'store_location' => 'Kopi Kita Selatan Taman',
                                'items' => [
                                    ['name' => 'Ice kopi gula aren', 'quantity' => 3],
                                ],
                                'passengers' => null,
                                'notes' => null,
                                'missing_fields' => [],
                            ])."\n```",
                        ],
                    ],
                ],
            ]),
        ]);

        $user = new User([
            'id' => 93,
            'name' => 'Aisy Profile',
            'phone' => '08123456789',
            'address' => 'Pendopo',
            'branch_id' => null,
        ]);

        $parsed = app(OrderParserService::class)->parse(
            $user,
            "Belikan ice kopi gula aren 3\nAlamat pembelian: Kopi Kita Selatan Taman\nKirim ke alamat saya",
        );

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['ai_parser']);
        $this->assertSame('Kopi Kita Selatan Taman', $parsed['store_location']);
        $this->assertSame('Kopi Kita Selatan Taman', $parsed['payload']['pickup_address']);
        $this->assertSame('alamat profile', $parsed['payload']['destination_address']);
        $this->assertSame('Kopi Kita Selatan Taman', $parsed['payload']['service_payload']['store_location']);
    }

    public function test_openrouter_ai_parser_uses_auto_free_model_when_auto_switch_is_enabled(): void
    {
        $settings = app(SettingService::class);
        $settings->set('ai_assistant_enabled', true);
        $settings->set('ai_provider', 'openrouter');
        $settings->set('ai_model', 'openrouter/auto');
        $settings->set('ai_base_url', 'https://openrouter.test/api/v1');
        $settings->set('openrouter_api_key', 'test-openrouter-key', true);

        Http::fakeSequence('openrouter.test/api/v1/chat/completions')
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'service_type' => 'ojek',
                                'pickup_address' => 'Rumah',
                                'destination_address' => 'Terminal',
                                'store_location' => null,
                                'items' => [],
                                'passengers' => 1,
                                'notes' => null,
                                'missing_fields' => [],
                            ]),
                        ],
                    ],
                ],
            ]);

        $user = new User([
            'id' => 94,
            'name' => 'Open Router User',
            'phone' => '08123456789',
            'address' => 'Jl. Merdeka',
            'branch_id' => null,
        ]);

        $parsed = app(OrderParserService::class)->parse($user, 'ojek dari rumah ke terminal');

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['ai_parser']);
        $this->assertSame('Terminal', $parsed['payload']['destination_address']);

        $sentModels = collect(Http::recorded())
            ->map(fn (array $record) => $record[0]->data()['model'] ?? null)
            ->filter()
            ->values()
            ->all();

        $this->assertSame(['openrouter/free'], $sentModels);
    }
}
