<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Models\Deal;
use App\Models\OutboundClick;
use App\Models\Publisher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/deals: the discounts and contests on the site today, ending soonest
 * first; nothing pending, ended, not yet started or undated and stale.
 */
class DealApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Noon in Tashkent on 12 November.
        CarbonImmutable::setTestNow('2026-11-12 07:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function deal(array $attributes = []): Deal
    {
        return Deal::create([
            'title' => 'Barcha kitoblarga 30% chegirma',
            'shop_name' => 'Asaxiy',
            'status' => ModerationStatus::Approved,
            'posted_at' => '2026-11-10 09:00',
            ...$attributes,
        ]);
    }

    public function test_only_deals_on_today_are_listed_ending_soonest_first(): void
    {
        $this->deal(['title' => 'Ends today', 'ends_on' => '2026-11-12']);
        $this->deal(['title' => 'Ends later', 'ends_on' => '2026-11-30']);
        $this->deal(['title' => 'No end, recent']);
        $this->deal(['title' => 'Ended', 'ends_on' => '2026-11-11']);
        $this->deal(['title' => 'Pending', 'status' => ModerationStatus::Pending]);
        $this->deal(['title' => 'Not started', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-30']);
        $this->deal(['title' => 'No end, stale', 'posted_at' => '2026-10-20 09:00']);

        $this->getJson('/api/deals')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Ends today')
            ->assertJsonPath('data.1.title', 'Ends later')
            ->assertJsonPath('data.2.title', 'No end, recent');
    }

    public function test_the_shape_the_site_expects(): void
    {
        $publisher = Publisher::create(['slug' => 'hilol-nashr', 'name' => 'Hilol-nashr']);
        $deal = $this->deal([
            'publisher_id' => $publisher->id,
            'shop_name' => null,
            'discount_percent' => 30,
            'promo_code' => 'KUZ30',
            'ends_on' => '2026-11-15',
            'source_url' => 'https://t.me/hilolnashr/77',
            'image_path' => 'announcements/77.jpg',
        ]);

        $this->getJson('/api/deals')
            ->assertJsonPath('data.0', [
                'id' => (string) $deal->id,
                'kind' => 'discount',
                'title' => 'Barcha kitoblarga 30% chegirma',
                'details' => null,
                'discountPercent' => 30,
                'promoCode' => 'KUZ30',
                'startsOn' => null,
                'endsOn' => '2026-11-15',
                // No shop page given: the post is where to take it up.
                'url' => 'https://t.me/hilolnashr/77',
                'sourceUrl' => 'https://t.me/hilolnashr/77',
                'imageUrl' => '/storage/announcements/77.jpg',
                'postedAt' => '2026-11-10T09:00:00+00:00',
                'source' => ['name' => 'Hilol-nashr', 'publisherSlug' => 'hilol-nashr'],
                'book' => null,
            ]);
    }

    public function test_it_filters_by_kind_and_publisher(): void
    {
        $publisher = Publisher::create(['slug' => 'sharq', 'name' => 'Sharq']);
        $this->deal(['title' => 'Sale']);
        $this->deal(['title' => 'Giveaway', 'kind' => 'contest', 'publisher_id' => $publisher->id, 'shop_name' => null]);

        $this->getJson('/api/deals?kind=contest')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Giveaway');
        $this->getJson('/api/deals?publisher=sharq')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Giveaway');
        $this->getJson('/api/deals?kind=nonsense')->assertJsonCount(2, 'data');
    }

    public function test_one_deal_is_found_only_while_it_is_on(): void
    {
        $on = $this->deal(['ends_on' => '2026-11-20']);
        $ended = $this->deal(['ends_on' => '2026-11-01']);

        $this->getJson("/api/deals/{$on->id}")->assertOk()->assertJsonPath('data.id', (string) $on->id);
        $this->getJson("/api/deals/{$ended->id}")->assertNotFound();
        $this->getJson('/api/deals/abc')->assertNotFound();
    }

    public function test_the_site_logs_a_deal_click_by_its_target(): void
    {
        config(['seo.revalidate.secret' => 'site-shared-secret']);
        $deal = $this->deal();

        $this->withToken('site-shared-secret')->postJson('/api/clicks', [
            'kind' => 'deal', 'target' => $deal->clickTarget(), 'locale' => 'uz',
        ])->assertNoContent();

        $this->withToken('site-shared-secret')->postJson('/api/clicks', ['kind' => 'deal', 'target' => 'asaxiy'])->assertUnprocessable();

        $this->assertSame(1, OutboundClick::query()->count());
        $this->assertSame(1, $deal->clicksCount());
    }
}
