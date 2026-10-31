<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\OutboundClickKind;
use App\Enums\UserRole;
use App\Filament\Resources\AnnouncementResource;
use App\Filament\Resources\AnnouncementResource\Pages\EditAnnouncement;
use App\Filament\Resources\AnnouncementResource\Pages\ListAnnouncements;
use App\Filament\Stats\SiteStatsOverview;
use App\Filament\Stats\TopAnnouncementsTable;
use App\Filament\Stats\TopStoresTable;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\OutboundClick;
use App\Models\Publisher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Publishers' new books and pre-orders: pasted from a Telegram post,
 * reviewed, shown on the site while live, and ordered through a counted
 * button.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'site-shared-secret';

    private Publisher $hilol;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-24 12:00:00');
        Storage::fake('public');
        config(['seo.revalidate.secret' => self::SECRET]);

        $this->hilol = Publisher::create([
            'slug' => 'hilol-nashr', 'name' => 'Hilol-nashr',
            'telegram_channel' => 'hilolnashr', 'order_contact' => 'hilol_buyurtma',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            't.me/s/hilolnashr*' => Http::response(file_get_contents(__DIR__.'/../Fixtures/telegram-channel.html')),
            't.me/s/stranger*' => Http::response('', 302, ['Location' => 'https://t.me/stranger']),
            'cdn4.telesco.pe/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function announcement(array $attributes = []): Announcement
    {
        static $n = 0;
        $n++;

        return Announcement::create([
            'publisher_id' => $this->hilol->id,
            'source_url' => "https://t.me/hilolnashr/{$n}",
            'title' => "Kitob {$n}",
            'status' => ModerationStatus::Approved,
            'posted_at' => now()->subDays(3),
            ...$attributes,
        ]);
    }

    public function test_pasting_a_post_link_makes_a_pending_draft_with_its_cover(): void
    {
        $this->admin();
        $this->fakeTelegram();

        Livewire::test(ListAnnouncements::class)
            ->callAction('paste', ['link' => 'https://t.me/hilolnashr/41?single'])
            ->assertHasNoActionErrors();

        $draft = Announcement::sole();
        $this->assertSame(ModerationStatus::Pending, $draft->status);
        $this->assertSame($this->hilol->id, $draft->publisher_id);
        $this->assertSame('https://t.me/hilolnashr/41', $draft->source_url);
        $this->assertSame('Jinoyat va jazo', $draft->title);
        $this->assertSame('Fyodor Dostoyevskiy', $draft->author_name);
        $this->assertSame(120000, $draft->price);
        $this->assertSame('2026-10-20 09:15:00', $draft->posted_at->format('Y-m-d H:i:s'));
        Storage::disk('public')->assertExists($draft->cover_path);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://t.me/s/hilolnashr?before=42'));
    }

    public function test_the_same_post_pasted_again_opens_the_first_draft(): void
    {
        $this->admin();
        $this->fakeTelegram();

        Livewire::test(ListAnnouncements::class)->callAction('paste', ['link' => 'https://t.me/hilolnashr/41']);
        Livewire::test(ListAnnouncements::class)
            ->callAction('paste', ['link' => 't.me/s/HilolNashr/41'])
            ->assertRedirect(AnnouncementResource::getUrl('edit', ['record' => Announcement::sole()]));

        $this->assertSame(1, Announcement::count());
    }

    public function test_a_channel_on_no_publisher_is_refused_unless_one_is_picked(): void
    {
        $this->admin();
        $this->fakeTelegram();
        $this->hilol->update(['telegram_channel' => null]);

        Livewire::test(ListAnnouncements::class)
            ->callAction('paste', ['link' => 'https://t.me/hilolnashr/41'])
            ->assertNotified('Could not read that post');
        $this->assertSame(0, Announcement::count());

        Livewire::test(ListAnnouncements::class)
            ->callAction('paste', ['link' => 'https://t.me/hilolnashr/41', 'publisher_id' => $this->hilol->id]);
        $this->assertSame($this->hilol->id, Announcement::sole()->publisher_id);
    }

    public function test_links_that_cannot_be_read_are_explained(): void
    {
        $this->admin();
        $this->fakeTelegram();
        Publisher::create(['slug' => 'stranger', 'name' => 'Stranger', 'telegram_channel' => 'stranger']);

        foreach (['https://example.com/x/1', 'https://t.me/c/123/4', 'https://t.me/stranger/5', 'https://t.me/hilolnashr/99'] as $link) {
            Livewire::test(ListAnnouncements::class)
                ->callAction('paste', ['link' => $link])
                ->assertNotified('Could not read that post');
        }

        $this->assertSame(0, Announcement::count());
    }

    public function test_an_admin_publishes_a_reviewed_draft(): void
    {
        $this->admin();
        $draft = $this->announcement(['status' => ModerationStatus::Pending]);

        Livewire::test(EditAnnouncement::class, ['record' => $draft->getRouteKey()])
            ->fillForm(['title' => 'Jinoyat va jazo', 'preorder_price' => 95000, 'preorder_deadline' => '2026-10-31'])
            ->callAction('publish');

        $draft->refresh();
        $this->assertSame(ModerationStatus::Approved, $draft->status);
        $this->assertSame('Jinoyat va jazo', $draft->title);
        $this->assertTrue($draft->isPreorder());
    }

    public function test_publishers_do_not_reach_the_queue(): void
    {
        $user = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password', 'role' => UserRole::Publisher]);
        $this->hilol->update(['user_id' => $user->id]);

        $this->actingAs($user)->get('/admin/announcements')->assertForbidden();
    }

    public function test_an_admin_opens_the_queue(): void
    {
        $this->admin();
        $this->announcement(['status' => ModerationStatus::Pending, 'title' => 'Jinoyat va jazo']);

        $this->get('/admin/announcements')->assertOk()->assertSee('Paste post link')->assertSee('Jinoyat va jazo');
    }

    public function test_a_publisher_may_set_its_order_contact_but_not_its_channel(): void
    {
        $user = User::create(['name' => 'P', 'email' => 'p@example.test', 'password' => 'password', 'role' => UserRole::Publisher]);
        $this->hilol->update(['user_id' => $user->id]);
        $this->actingAs($user);

        $this->hilol->update(['telegram_channel' => 'someone_else', 'order_contact' => 'new_contact']);

        $this->hilol->refresh();
        $this->assertSame('hilolnashr', $this->hilol->telegram_channel);
        $this->assertSame('new_contact', $this->hilol->order_contact);
    }

    public function test_the_site_gets_only_what_is_live_today(): void
    {
        $open = $this->announcement(['title' => 'Open pre-order', 'preorder_deadline' => '2026-10-24', 'preorder_price' => 90000]);
        $later = $this->announcement(['title' => 'Later pre-order', 'preorder_deadline' => '2026-11-10']);
        $new = $this->announcement(['title' => 'New book']);
        $this->announcement(['title' => 'Closed pre-order', 'preorder_deadline' => '2026-10-23']);
        $this->announcement(['title' => 'Old news', 'posted_at' => now()->subDays(Announcement::NEW_FOR_DAYS + 1)]);
        $this->announcement(['title' => 'Pending', 'status' => ModerationStatus::Pending]);
        $this->announcement(['title' => 'Rejected', 'status' => ModerationStatus::Rejected]);

        $response = $this->getJson('/api/announcements')->assertOk();

        // Pre-orders closing soonest first, then new books.
        $this->assertSame(['Open pre-order', 'Later pre-order', 'New book'], array_column($response->json('data'), 'title'));
        $this->assertSame([
            'id' => (string) $open->id,
            'title' => 'Open pre-order',
            'author' => null,
            'translator' => null,
            'isPreorder' => true,
            'price' => null,
            'preorderPrice' => 90000,
            'preorderDeadline' => '2026-10-24',
            'coverUrl' => null,
            'postedAt' => '2026-10-21T12:00:00+00:00',
            'sourceUrl' => $open->source_url,
            'orderContact' => 'hilol_buyurtma',
            'publisher' => ['slug' => 'hilol-nashr', 'name' => 'Hilol-nashr'],
            'bookId' => null,
        ], $response->json('data.0'));

        $this->getJson("/api/announcements/{$later->id}")->assertOk()->assertJsonPath('data.title', 'Later pre-order');
        $this->getJson('/api/announcements/'.Announcement::where('title', 'Closed pre-order')->value('id'))->assertNotFound();
        $this->getJson('/api/announcements/'.Announcement::where('title', 'Pending')->value('id'))->assertNotFound();
        $this->getJson('/api/announcements/abc')->assertNotFound();
        $this->assertNotNull($new);
    }

    public function test_a_pre_order_leaves_after_its_last_tashkent_day(): void
    {
        $this->announcement(['title' => 'Last day', 'preorder_deadline' => '2026-10-24']);

        // 18:59 UTC is 23:59 in Tashkent: still the 24th.
        CarbonImmutable::setTestNow('2026-10-24 18:59:00');
        $this->assertSame(1, Announcement::live()->count());

        CarbonImmutable::setTestNow('2026-10-24 19:00:00');
        $this->assertSame(0, Announcement::live()->count());
    }

    public function test_a_publishers_page_gets_its_own(): void
    {
        $other = Publisher::create(['slug' => 'yangi-asr', 'name' => 'Yangi asr']);
        $this->announcement(['title' => 'Hilol book']);
        $this->announcement(['title' => 'Asr book', 'publisher_id' => $other->id]);

        $this->assertSame(['Asr book'], array_column($this->getJson('/api/announcements?publisher=yangi-asr')->json('data'), 'title'));
    }

    public function test_an_order_click_is_counted_against_the_announcements_publisher_and_book(): void
    {
        $book = Book::create(['uzbek_title' => 'Jinoyat va jazo']);
        $announcement = $this->announcement(['book_id' => $book->id]);

        $this->withToken(self::SECRET)->postJson('/api/clicks', [
            'kind' => 'publisher_order',
            'announcement_id' => $announcement->id,
            // Ignored: the publisher comes from the announcement.
            'target' => 'asaxiy',
            'locale' => 'uz',
            'ip' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
        ])->assertNoContent();

        $click = OutboundClick::sole();
        $this->assertSame(OutboundClickKind::PublisherOrder, $click->kind);
        $this->assertSame('hilol-nashr', $click->target);
        $this->assertSame($announcement->id, $click->announcement_id);
        $this->assertSame($book->id, $click->book_id);

        $this->withToken(self::SECRET)->postJson('/api/clicks', ['kind' => 'publisher_order', 'locale' => 'uz'])->assertUnprocessable();
        $this->withToken(self::SECRET)->postJson('/api/clicks', ['kind' => 'store', 'locale' => 'uz'])->assertUnprocessable();
    }

    public function test_the_stats_page_counts_orders_apart_from_stores(): void
    {
        $this->admin();
        $announcement = $this->announcement(['title' => 'Jinoyat va jazo']);

        foreach (['a', 'a', 'b'] as $visitor) {
            OutboundClick::create(['kind' => 'publisher_order', 'announcement_id' => $announcement->id, 'target' => 'hilol-nashr', 'locale' => 'uz', 'visitor_hash' => $visitor]);
        }
        OutboundClick::create(['kind' => 'store', 'target' => 'asaxiy', 'locale' => 'uz', 'visitor_hash' => 'c']);

        $orders = Livewire::test(TopAnnouncementsTable::class, ['filters' => []])->instance()->getTableRecords();
        $this->assertSame(3, (int) $orders->sole()->orders);
        $this->assertSame(2, (int) $orders->sole()->visitors);

        Livewire::test(TopStoresTable::class, ['filters' => []])->assertSee('asaxiy')->assertDontSee('hilol-nashr');
        Livewire::test(SiteStatsOverview::class, ['filters' => []])
            ->assertSeeInOrder(['Store clicks', '1', 'Publisher orders', '3']);
    }
}
