<?php

namespace App\Services\Telegram;

use App\Enums\ModerationStatus;
use App\Models\Announcement;
use App\Models\Publisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;

/**
 * Reads every publisher's Telegram channel for posts that came since the
 * last scan, and makes a pending draft from each that looks like a new book
 * or a pre-order (AnnouncementDraft::looksLikeAnnouncement). Nothing reaches
 * the site before an admin publishes it.
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
                [$posts, $drafts] = $this->scan($publisher);
            } catch (TelegramChannelException $e) {
                $report['errors'][$publisher->telegram_channel] = $e->getMessage();

                continue;
            }

            $report['channels']++;
            $report['posts'] += $posts;
            $report['drafts'] += $drafts;
        }

        return $report;
    }

    /**
     * @return array{0: int, 1: int} New posts read, and drafts made from them.
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

        foreach ($new as $post) {
            if (AnnouncementDraft::looksLikeAnnouncement($post->text) && $this->importer->fromPost($post, $publisher) !== null) {
                $drafts++;
            }
        }

        $publisher->forceFill([
            'telegram_seen_post_id' => $newest ?: null,
            'telegram_scanned_at' => now(),
        ])->saveQuietly();

        return [count($new), $drafts];
    }
}
