<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\AnnouncementResource\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Deal;
use App\Models\Publisher;
use App\Models\User;
use App\Services\Telegram\ChannelScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The hourly read of publishers' Telegram channels: posts that look like a
 * new book or a pre-order become pending drafts, each post is read once,
 * and nothing reaches the site before an admin publishes it.
 */
class ChannelScanTest extends TestCase
{
    use RefreshDatabase;

    private Publisher $hilol;

    /** @var array<int, array{text: string, at: string, media: string, forwardedFrom: ?string}> The channel, by post id. */
    private array $channel = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-24 12:00:00');
        Storage::fake('public');
        Sleep::fake();

        $this->hilol = Publisher::create([
            'slug' => 'hilol-nashr', 'name' => 'Hilol-nashr',
            'telegram_channel' => 'hilolnashr', 'order_contact' => 'hilol_buyurtma',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'telesco.pe/')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            if (str_starts_with($url, 'https://t.me/s/hilolnashr')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                return Http::response($this->page(isset($query['before']) ? (int) $query['before'] : null));
            }

            return Http::response('', 302, ['Location' => 'https://t.me/nobody']);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  string  $media  photo, video or none
     * @param  ?string  $forwardedFrom  a channel, '' for a hidden source, null for the channel's own post
     */
    private function channelPost(int $id, string $text, string $at = '2026-10-23 09:00', string $media = 'photo', ?string $forwardedFrom = null): void
    {
        $this->channel[$id] = ['text' => $text, 'at' => $at, 'media' => $media, 'forwardedFrom' => $forwardedFrom];
    }

    /** Up to 20 posts before $before, oldest first, as t.me/s shows them (Channel "HilolNashr" in its own case). */
    private function page(?int $before): string
    {
        $ids = array_keys($this->channel);
        sort($ids);
        $ids = array_slice(array_values(array_filter($ids, fn (int $id) => $before === null || $id < $before)), -20);

        $html = '<section class="tgme_channel_history js-message_history">';
        foreach ($ids as $id) {
            $post = $this->channel[$id];
            $at = CarbonImmutable::parse($post['at'])->toIso8601String();
            $text = nl2br(htmlspecialchars($post['text']));
            $media = match ($post['media']) {
                'photo' => "<a class=\"tgme_widget_message_photo_wrap\" style=\"background-image:url('https://cdn4.telesco.pe/file/{$id}.jpg')\"></a>",
                'video' => "<a class=\"tgme_widget_message_video_player\"><i class=\"tgme_widget_message_video_thumb\" style=\"background-image:url('https://cdn4.telesco.pe/file/v{$id}.jpg')\"></i></a>",
                default => '',
            };
            $forward = match ($post['forwardedFrom']) {
                null => '',
                '' => '<div class="tgme_widget_message_forwarded_from accent_color">Forwarded from <span class="tgme_widget_message_forwarded_from_name">Someone</span></div>',
                default => "<div class=\"tgme_widget_message_forwarded_from accent_color\">Forwarded from <a class=\"tgme_widget_message_forwarded_from_name\" href=\"https://t.me/{$post['forwardedFrom']}/7\">{$post['forwardedFrom']}</a></div>",
            };
            $html .= <<<HTML
                <div class="tgme_widget_message_wrap"><div class="tgme_widget_message js-widget_message" data-post="HilolNashr/{$id}">
                  {$forward}
                  {$media}
                  <div class="tgme_widget_message_text js-message_text" dir="auto">{$text}</div>
                  <a class="tgme_widget_message_date"><time datetime="{$at}" class="time">x</time></a>
                </div></div>
                HTML;
        }

        return $html.'</section>';
    }

    private function scan(): array
    {
        return app(ChannelScanner::class)->scanAll();
    }

    public function test_posts_that_look_like_announcements_become_pending_drafts(): void
    {
        $this->channelPost(100, "#yangi_nashr\n«Jinoyat va jazo»\nMuallif: Fyodor Dostoyevskiy\nNarxi: 120 000 so'm");
        $this->channelPost(101, "Bugun do'konlarimiz 10:00 dan ishlaydi");
        // A book long in print, advertised with its price: not news.
        $this->channelPost(104, "«Kapitalizm va erkinlik» — erkin bozor haqida o‘ylang. Narxi: 99 000 so'm");
        $this->channelPost(102, "«Hayola» kitobiga oldindan buyurtma: 79 000 so'm, 30-oktabrgacha");

        $report = $this->scan();

        $this->assertSame(['channels' => 1, 'posts' => 4, 'drafts' => 2, 'errors' => []], $report);

        $first = Announcement::query()->where('source_url', 'https://t.me/HilolNashr/100')->sole();
        $this->assertSame(ModerationStatus::Pending, $first->status);
        $this->assertTrue($first->found_by_scan);
        $this->assertSame('Jinoyat va jazo', $first->title);
        $this->assertSame('Fyodor Dostoyevskiy', $first->author_name);
        $this->assertSame(120000, $first->price);
        $this->assertSame($this->hilol->id, $first->publisher_id);
        Storage::disk('public')->assertExists($first->cover_path);

        $preorder = Announcement::query()->where('source_url', 'https://t.me/HilolNashr/102')->sole();
        $this->assertSame('2026-10-30', $preorder->preorder_deadline->toDateString());

        // Nothing is on the site until an admin publishes it.
        $this->assertSame(0, Announcement::query()->live()->count());

        $this->hilol->refresh();
        $this->assertSame(104, $this->hilol->telegram_seen_post_id);
        $this->assertNotNull($this->hilol->telegram_scanned_at);
    }

    public function test_each_post_is_read_once_and_rejected_posts_stay_rejected(): void
    {
        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");
        $this->scan();

        Announcement::query()->update(['status' => ModerationStatus::Rejected]);
        // Forget where the scan got to: the post is read again, but already has its answer.
        $this->hilol->forceFill(['telegram_seen_post_id' => null])->saveQuietly();
        $this->assertSame(0, $this->scan()['drafts']);

        $this->channelPost(103, "#sotuvda «Hayola» — 79 000 so'm");
        $report = $this->scan();

        $this->assertSame(1, $report['posts']);
        $this->assertSame(1, $report['drafts']);
        $this->assertSame(2, Announcement::query()->count());
        $this->assertSame(ModerationStatus::Rejected, Announcement::query()->where('source_url', 'https://t.me/HilolNashr/100')->sole()->status);
    }

    public function test_only_posts_with_a_photo_become_drafts(): void
    {
        $this->channelPost(100, "#yangi_nashr «Jinoyat va jazo» — 120 000 so'm");
        $this->channelPost(101, "#yangi_nashr «Hayola» kitobidan parcha — 79 000 so'm", media: 'video');
        $this->channelPost(102, "#yangi_nashr «Soʻnggi kun» — 60 000 so'm", media: 'none');

        $report = $this->scan();

        $this->assertSame(3, $report['posts']);
        $this->assertSame(1, $report['drafts']);
        $this->assertSame(['Jinoyat va jazo'], Announcement::query()->pluck('title')->all());
    }

    public function test_posts_forwarded_from_other_channels_are_not_this_publishers_news(): void
    {
        $this->channelPost(100, "#yangi_nashr «Jinoyat va jazo» — 120 000 so'm", forwardedFrom: 'boshqa_nashriyot');
        $this->channelPost(101, "#yangi_nashr «Hayola» — 79 000 so'm", forwardedFrom: '');
        // Its own older post, forwarded again: still its own book.
        $this->channelPost(102, "#yangi_nashr «Soʻnggi kun» — 60 000 so'm", forwardedFrom: 'hilolnashr');

        $this->assertSame(1, $this->scan()['drafts']);
        $this->assertSame(['Soʻnggi kun'], Announcement::query()->pluck('title')->all());
    }

    public function test_a_book_posted_again_is_not_a_second_draft(): void
    {
        $this->channelPost(100, "#tez_kunda\n«Jinoyat va jazo» kitobiga oldindan buyurtma: 95 000 so'm");
        $this->channelPost(101, "📚 YANGI KITOB!\n«JINOYAT VA JAZO»\nNarxi: 120 000 so'm");
        // The same title in Cyrillic.
        $this->channelPost(102, "Сотувда!\n«Жиноят ва жазо»\nНархи: 120 000 сўм");

        $this->assertSame(1, $this->scan()['drafts']);
        $this->assertSame(1, Announcement::query()->count());
    }

    public function test_a_book_waiting_or_published_is_skipped_but_a_rejected_one_is_not(): void
    {
        $make = fn (int $id, string $title, ModerationStatus $status) => Announcement::create([
            'publisher_id' => $this->hilol->id, 'source_url' => "https://t.me/hilolnashr/{$id}",
            'title' => $title, 'status' => $status,
        ]);
        $make(1, 'Jinoyat va jazo', ModerationStatus::Pending);
        $make(2, 'Hayola', ModerationStatus::Approved);
        $make(3, 'Soʻnggi kun', ModerationStatus::Rejected);
        // Another publisher's announcement of the same title is theirs.
        Announcement::create([
            'publisher_id' => Publisher::create(['slug' => 'boshqa', 'name' => 'Boshqa'])->id,
            'source_url' => 'https://t.me/boshqa/1', 'title' => 'Ota', 'status' => ModerationStatus::Approved,
        ]);

        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");
        $this->channelPost(101, "#sotuvda «Hayola» — 79 000 so'm");
        $this->channelPost(102, "#sotuvda «Soʻnggi kun» — 60 000 so'm");
        $this->channelPost(103, "#sotuvda «Ota» — 50 000 so'm");

        $this->assertSame(2, $this->scan()['drafts']);
        $this->assertEqualsCanonicalizing(
            ['https://t.me/HilolNashr/102', 'https://t.me/HilolNashr/103'],
            Announcement::query()->where('found_by_scan', true)->pluck('source_url')->all(),
        );
    }

    public function test_a_book_this_publisher_already_has_in_the_catalogue_is_skipped(): void
    {
        $own = Book::create(['uzbek_title' => 'Jinoyat va jazo', 'original_title' => 'Преступление и наказание']);
        $own->editions()->create(['publisher_id' => $this->hilol->id, 'language' => 'uz']);
        // Only another publisher prints this one: a new translation is news.
        $theirs = Book::create(['uzbek_title' => 'Hayola', 'original_title' => 'Hayola']);
        $theirs->editions()->create(['publisher_id' => Publisher::create(['slug' => 'boshqa', 'name' => 'Boshqa'])->id, 'language' => 'uz']);

        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");
        $this->channelPost(101, "#sotuvda «Преступление и наказание» — 120 000 so'm");
        $this->channelPost(102, "#sotuvda «Hayola» — 79 000 so'm");

        $this->assertSame(1, $this->scan()['drafts']);
        $this->assertSame(['Hayola'], Announcement::query()->pluck('title')->all());
    }

    public function test_a_post_that_cannot_be_saved_does_not_stop_the_others(): void
    {
        Announcement::creating(function (Announcement $announcement) {
            if ($announcement->title === 'Buzuq') {
                throw new RuntimeException('database said no');
            }
        });
        $this->channelPost(100, "#sotuvda «Buzuq» — 50 000 so'm");
        $this->channelPost(101, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");

        $report = $this->scan();

        $this->assertSame(1, $report['drafts']);
        $this->assertStringContainsString('https://t.me/HilolNashr/100', $report['errors']['hilolnashr']);
        $this->assertSame(101, $this->hilol->refresh()->telegram_seen_post_id);
    }

    public function test_a_pasted_post_is_not_made_twice(): void
    {
        Announcement::create([
            'publisher_id' => $this->hilol->id, 'source_url' => 'https://t.me/hilolnashr/100',
            'title' => 'Pasted before', 'status' => ModerationStatus::Approved,
        ]);
        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");

        $this->assertSame(0, $this->scan()['drafts']);
        $this->assertSame(1, Announcement::query()->count());
    }

    public function test_old_posts_are_not_news(): void
    {
        $this->channelPost(90, "#sotuvda «Eski kitob» — 50 000 so'm", '2026-10-01 09:00');
        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");

        $report = $this->scan();

        $this->assertSame(1, $report['drafts']);
        $this->assertFalse(Announcement::query()->where('source_url', 'like', '%/90')->exists());
    }

    public function test_a_busy_channel_is_read_back_to_where_the_last_scan_stopped(): void
    {
        $this->hilol->forceFill(['telegram_seen_post_id' => 200])->saveQuietly();

        foreach (range(190, 245) as $id) {
            $this->channelPost($id, "#yangi_nashr «Kitob {$id}» — 50 000 so'm");
        }

        $report = $this->scan();

        // 201–245 are new: three pages (226–245, 206–225, 190–205).
        $this->assertSame(45, $report['posts']);
        $this->assertSame(45, $report['drafts']);
        $this->assertFalse(Announcement::query()->where('source_url', 'like', '%/200')->exists());
        $this->assertSame(245, $this->hilol->refresh()->telegram_seen_post_id);
        Http::assertSentCount(3 + 45);
    }

    public function test_the_first_scan_of_a_channel_reads_one_page(): void
    {
        foreach (range(1, 30) as $id) {
            $this->channelPost($id, "#yangi_nashr «Kitob {$id}» — 50 000 so'm");
        }

        $this->assertSame(20, $this->scan()['drafts']);
        $this->assertSame(30, $this->hilol->refresh()->telegram_seen_post_id);
    }

    public function test_one_unreadable_channel_does_not_stop_the_others(): void
    {
        Publisher::create(['slug' => 'yopiq', 'name' => 'Yopiq', 'telegram_channel' => 'yopiqkanal']);
        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");

        $report = $this->scan();

        $this->assertSame(1, $report['channels']);
        $this->assertSame(1, $report['drafts']);
        $this->assertArrayHasKey('yopiqkanal', $report['errors']);

        $this->artisan('announcements:scan')
            ->expectsOutputToContain('@yopiqkanal')
            ->assertFailed();
    }

    public function test_the_command_reports_what_it_found(): void
    {
        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");

        $this->artisan('announcements:scan', ['--publisher' => 'hilol-nashr'])
            ->expectsOutput('1 channel(s) read: 1 new post(s), 1 draft(s) waiting for review.')
            ->assertSuccessful();

        $this->artisan('announcements:scan', ['--publisher' => 'nobody'])->assertFailed();
    }

    public function test_a_new_channel_is_scanned_afresh(): void
    {
        $this->hilol->forceFill(['telegram_seen_post_id' => 500, 'telegram_scanned_at' => now()])->saveQuietly();

        $this->hilol->update(['telegram_channel' => 'hilol_yangi']);

        $this->assertNull($this->hilol->refresh()->telegram_seen_post_id);
        $this->assertNull($this->hilol->telegram_scanned_at);
    }

    public function test_an_admin_scans_from_the_queue(): void
    {
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a@example.test',
            'password' => 'password', 'role' => UserRole::Admin,
        ]));
        $this->channelPost(100, "#sotuvda «Jinoyat va jazo» — 120 000 so'm");

        Livewire::test(ListAnnouncements::class)
            ->callAction('scan')
            ->assertNotified('1 new draft to review');

        $this->assertSame(1, Announcement::query()->where('found_by_scan', true)->count());
    }

    public function test_recheck_rejects_the_scans_drafts_that_are_not_news(): void
    {
        $make = fn (string $url, string $text, bool $scanned, ModerationStatus $status = ModerationStatus::Pending) => Announcement::create([
            'publisher_id' => $this->hilol->id, 'source_url' => $url, 'title' => $url,
            'post_text' => $text, 'found_by_scan' => $scanned, 'status' => $status,
        ]);

        $make('https://t.me/hilolnashr/1', '⭐️ TOP-5 kitob: Molxona - 30 000 so‘m', scanned: true);
        $make('https://t.me/hilolnashr/2', "#yangi_nashr «Jinoyat va jazo» — 120 000 so'm", scanned: true);
        // Pasted by an admin, or already published: theirs to keep.
        $make('https://t.me/hilolnashr/3', "«Kapitalizm va erkinlik» Narxi: 99 000 so'm", scanned: false);
        $make('https://t.me/hilolnashr/4', "«Kapitalizm va erkinlik» Narxi: 99 000 so'm", scanned: true, status: ModerationStatus::Approved);

        $this->artisan('announcements:scan', ['--recheck' => true])
            ->expectsOutput('  rejected: https://t.me/hilolnashr/1')
            ->expectsOutput('1 pending draft(s) rejected.')
            ->assertSuccessful();

        $this->assertSame(
            ['https://t.me/hilolnashr/1'],
            Announcement::query()->where('status', ModerationStatus::Rejected)->pluck('source_url')->all(),
        );
        Http::assertNothingSent();
    }

    public function test_a_sale_or_contest_post_becomes_a_deal_draft_not_an_announcement(): void
    {
        $this->channelPost(110, "🔥 Barcha kitoblarga 30% chegirma!\nPromokod: KUZ30\n15-noyabrgacha amal qiladi.", '2026-10-23 09:00', 'none');
        $this->channelPost(111, "📚 KONKURS! 3 ta kitobni yutib oling. Shartlar: kanalga obuna bo'ling va postni ulashing.");

        $report = $this->scan();

        $this->assertSame(2, $report['drafts']);
        $this->assertSame(0, Announcement::query()->count());

        $sale = Deal::query()->where('source_url', 'https://t.me/HilolNashr/110')->sole();
        $this->assertSame(ModerationStatus::Pending, $sale->status);
        $this->assertTrue($sale->found_by_scan);
        $this->assertSame($this->hilol->id, $sale->publisher_id);
        $this->assertSame(30, $sale->discount_percent);
        $this->assertSame('KUZ30', $sale->promo_code);
        $this->assertSame('2026-11-15', $sale->ends_on->toDateString());
        $this->assertNull($sale->image_path);

        $contest = Deal::query()->where('source_url', 'https://t.me/HilolNashr/111')->sole();
        $this->assertSame('contest', $contest->kind->value);
        $this->assertNotNull($contest->image_path);

        // Read once: the next scan drafts nothing again.
        $this->assertSame(0, $this->scan()['drafts']);
    }
}
