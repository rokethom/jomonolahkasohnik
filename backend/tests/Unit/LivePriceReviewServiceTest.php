<?php

namespace Tests\Unit;

use App\Models\LivePriceReview;
use App\Models\User;
use App\Services\LivePriceReviewService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class LivePriceReviewServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_live_price_review_accepts_unparsed_preview_when_enabled(): void
    {
        if (! Schema::hasTable('live_price_reviews')) {
            $this->markTestSkipped('live_price_reviews table is not available in this local test database.');
        }

        $settings = Mockery::mock(SettingService::class);
        $settings->shouldReceive('bool')->with('live_price_review_enabled', false)->andReturn(true);
        $settings->shouldReceive('int')->with('live_price_review_delay_seconds', 5)->andReturn(5);

        $service = new LivePriceReviewService($settings);

        $user = User::query()->create([
            'name' => 'Fallback Customer',
            'username' => 'fallback_customer',
            'email' => 'fallback-customer@example.test',
            'phone' => '08111112222',
            'address' => 'Rumah fallback',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'is_active' => true,
        ]);

        $review = $service->createFromPreview($user, 'Please buy anything near BRI', [
            'intent' => 'fallback_form',
            'reply' => 'JOJOBOT belum bisa membaca format pesanan.',
            'parsed' => [],
            'order_payload' => null,
            'quote' => null,
        ]);

        $this->assertInstanceOf(LivePriceReview::class, $review);
        $this->assertSame(LivePriceReview::STATUS_PENDING, $review->status);
        $this->assertSame('delivery', $review->service_type);
        $this->assertTrue((bool) data_get($review->parsed, 'parser_needs_human_review'));
        $this->assertTrue((bool) data_get($review->order_payload, 'service_payload.parser_needs_human_review'));
        $this->assertSame(0, $review->system_total_price);
    }
}
