<?php

namespace App\Services\Telegram;

use App\Enums\ModerationStatus;
use App\Models\Announcement;
use App\Models\Book;
use App\Models\Publisher;
use App\Support\UzbekText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Reads every publisher's Telegram channel for posts that came since the
 * last scan, and makes a pending draft from each that looks like a new book
 * or a pre-order (AnnouncementDraft::looksLikeAnnouncement). Nothing reaches
 * the site before an admin publishes it.
 *
 * Only the channel's own posts with a photo count: a new book is announced
 * with its cover, while videos are trailers and readings, and a forward is
 * someone else's news. A book the publisher already has, in the catalogue
 * or as an announcement waiting or published, is not news either: channels
 * post the same book again and again (teaser, pre-order, "now on sale").
 * An admin can still paste any post the scan passed over.
 *
 * One request per channel in the usual hour; more pages only when a channel
 * posted more than a page since the last scan. Run by announcements:scan.
 */
class ChannelScanner
{
    /**
     * Older posts are not news, so they never become drafts: the first scan
     * of a channel, or one after a long gap, reads back this far at most.
     */
    public const LOOK_BACK_DAYS = 14;

    /** A page has up to 20 posts; this bounds a scan of a very busy channel. */
    private const MAX_PAGES = 5;

    public function __construct(
        private readonly TelegramChannel $telegram,
        private readonly AnnouncementImporter $importer,
    ) {}

    /**
     * Rejects the scan's drafts still waiting for review whose post no
     * longer passes looksLikeAnnouncement, after the check has been made
     * stricter. Pasted drafts are an admin's own choice and are left alone.
     *
     * @return list<string> The titles rejected.
     */
    public function rejectStaleDrafts(): array
    {
        $rejected = [];

        Announcement::query()
            ->where('status', ModerationStatus::Pending)
            ->where('found_by_scan', true)
            ->each(function (Announcement $announcement) use (&$rejected) {
                if (! AnnouncementDraft::looksLikeAnnouncement($announcement->post_text)) {
                    $announcement->update(['status' => ModerationStatus::Rejected]);
                    $rejected[] = $announcement->title;
                }
            });

        return $rejected;
    }

    /**
     * @return array{channels: int, posts: int, drafts: int, errors: array<string, string>}
     */
    public function scanAll(?Publisher $only = null): array
    {
        $report = ['channels' => 0, 'posts' => 0, 'drafts' => 0, 'errors' => []];

        $publishers = $only ? collect([$only]) : Publisher::query()
            ->whereNotNull('telegram_channel')
            ->where('telegram_channel', '!=', '')
            ->orderBy('id')
            ->get();

        foreach ($publishers->values() as $i => $publisher) {
            if ($i > 0) {
                // Gentle on t.me: one channel at a time, a breath between.
                Sleep::for(1)->second();
            }

            try {
                [$posts, $drafts, $failed] = $this->scan($publisher);
            } catch (TelegramChannelException $e) {
                $report['errors'][$publisher->telegram_channel] = $e->getMessage();

                continue;
            }

            if ($failed !== []) {
                $report['errors'][$publisher->telegram_channel] = 'No draft made from '.implode(', ', $failed).': see the log. Paste the link to add it by hand.';
            }

            $report['channels']++;
            $report['posts'] += $posts;
            $report['drafts'] += $drafts;
        }

        return $report;
    }

    /**
     * @return array{0: int, 1: int, 2: list<string>} New posts read, drafts
     *                                                made from them, and the
     *                                                links of posts that failed.
     */
    public function scan(Publisher $publisher): array
    {
        $channel = (string) $publisher->telegram_channel;
        $seen = $publisher->telegram_seen_post_id;
        $since = CarbonImmutable::now()->subDays(self::LOOK_BACK_DAYS);

        $new = [];
        $newest = $seen ?? 0;
        $before = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $posts = array_filter(
                $this->telegram->page($channel, $before),
                fn (TelegramPost $post) => strcasecmp($post->channel, $channel) === 0,
            );

            if ($posts === []) {
                break;
            }

            $ids = array_map(fn (TelegramPost $post) => $post->id, $posts);
            $newest = max($newest, ...$ids);
            $reachedEnd = false;
            foreach ($posts as $post) {
                if (($seen !== null && $post->id <= $seen) || ($post->postedAt !== null && $post->postedAt->lt($since))) {
                    $reachedEnd = true;

                    continue;
                }

                $new[$post->id] = $post;
            }

            // The first scan takes one page: what a channel posted before
            // the scan was set up is the admin's to paste, if wanted.
            if ($reachedEnd || $seen === null) {
                break;
            }

            $before = min($ids);
        }

        ksort($new);
        $drafts = 0;
        $failed = [];
        $catalogue = null;

        foreach ($new as $post) {
            if (! $post->hasPhoto || $post->isForwardedFromElsewhere() || ! AnnouncementDraft::looksLikeAnnouncement($post->text)) {
                continue;
            }

            // One post that cannot be saved must not cost the channels after it.
            try {
                $catalogue ??= $this->catalogueTitles($publisher);

                if ($this->alreadyKnown($post, $publisher, $catalogue)) {
                    continue;
                }

                if ($this->importer->fromPost($post, $publisher) !== null) {
                    $drafts++;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $post->url();
            }
        }

        $publisher->forceFill([
            'telegram_seen_post_id' => $newest ?: null,
            'telegram_scanned_at' => now(),
        ])->saveQuietly();

        return [count($new), $drafts, $failed];
    }

    /**
     * Whether the post's book is one the publisher already has: a pending
     * or published announcement, or a catalogue book with this publisher's
     * edition, under the same title in any alphabet or spelling. Rejected
     * announcements do not count: an admin may have turned down a teaser,
     * not the book. A title too short to tell books apart never matches.
     *
     * @param  array<string, true>  $catalogue
     */
    private function alreadyKnown(TelegramPost $post, Publisher $publisher, array $catalogue): bool
    {
        $key = UzbekText::key(AnnouncementDraft::fromText($post->text, $post->postedAt)['title']);

        if (mb_strlen($key) < 3 || $key === UzbekText::key('Untitled post')) {
            return false;
        }

        if (isset($catalogue[$key])) {
            return true;
        }

        return Announcement::query()
            ->where('publisher_id', $publisher->id)
            ->where('status', '!=', ModerationStatus::Rejected)
            ->pluck('title')
            ->contains(fn (string $title) => UzbekText::key($title) === $key);
    }

    /**
     * The titles of the books this publisher has an edition of, as keys.
     *
     * @return array<string, true>
     */
    private function catalogueTitles(Publisher $publisher): array
    {
        $keys = [];

        Book::query()
            ->whereHas('editions', fn ($editions) => $editions->where('publisher_id', $publisher->id))
            ->select(['id', 'uzbek_title', 'original_title'])
            ->each(function (Book $book) use (&$keys) {
                foreach ([$book->uzbek_title, $book->original_title] as $title) {
                    if (filled($title)) {
                        $keys[UzbekText::key($title)] = true;
                    }
                }
            });

        return $keys;
    }
}
