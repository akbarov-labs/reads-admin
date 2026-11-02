<?php

namespace App\Services\Telegram;

use App\Enums\ModerationStatus;
use App\Models\Announcement;
use App\Models\Publisher;

/**
 * Turns a post into a pending announcement: saves its cover and fills what
 * it can from the text. The admin checks the rest before approving. Posts
 * come from a pasted link (import) or from the hourly scan of publishers'
 * channels (ChannelScanner, through fromPost).
 */
class AnnouncementImporter
{
    public function __construct(private readonly TelegramChannel $telegram) {}

    /**
     * @return array{0: Announcement, 1: bool} The announcement, and whether
     *                                         it was made now (false: that
     *                                         post was pasted before).
     */
    public function import(string $link, ?Publisher $publisher = null): array
    {
        $parsed = TelegramChannel::parseLink($link);

        if ($parsed === null) {
            throw new TelegramChannelException('That is not a link to a post on a public channel. Copy it from the post in Telegram: Copy Post Link.');
        }

        [$channel, $id] = $parsed;

        $publisher ??= Publisher::query()->whereRaw('lower(telegram_channel) = ?', [strtolower($channel)])->first();

        if ($publisher === null) {
            throw new TelegramChannelException("No publisher has @{$channel} as its Telegram channel. Add it on the publisher, or pick the publisher here.");
        }

        $existing = Announcement::query()->whereRaw('lower(source_url) = ?', [strtolower("https://t.me/{$channel}/{$id}")])->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        return [$this->create($this->telegram->post($channel, $id), $publisher, foundByScan: false), true];
    }

    /**
     * A draft from a post already read, unless that post has one (pasted,
     * scanned, or rejected before: a rejected post stays rejected).
     */
    public function fromPost(TelegramPost $post, Publisher $publisher): ?Announcement
    {
        if (Announcement::query()->whereRaw('lower(source_url) = ?', [strtolower($post->url())])->exists()) {
            return null;
        }

        return $this->create($post, $publisher, foundByScan: true);
    }

    private function create(TelegramPost $post, Publisher $publisher, bool $foundByScan): Announcement
    {
        return Announcement::create([
            ...AnnouncementDraft::fromText($post->text, $post->postedAt),
            'publisher_id' => $publisher->id,
            'source_url' => $post->url(),
            'posted_at' => $post->postedAt,
            'post_text' => $post->text,
            'cover_path' => $this->telegram->saveCover($post),
            'status' => ModerationStatus::Pending,
            'found_by_scan' => $foundByScan,
        ]);
    }
}
