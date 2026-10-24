<?php

namespace App\Services\Telegram;

use App\Enums\ModerationStatus;
use App\Models\Announcement;
use App\Models\Publisher;

/**
 * Turns a pasted post link into a pending announcement: reads the post,
 * saves its cover, and fills what it can from the text. The admin checks the
 * rest before approving.
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

        $post = $this->telegram->post($channel, $id);
        $draft = AnnouncementDraft::fromText($post->text, $post->postedAt);

        $announcement = Announcement::create([
            ...$draft,
            'publisher_id' => $publisher->id,
            'source_url' => $post->url(),
            'posted_at' => $post->postedAt,
            'post_text' => $post->text,
            'cover_path' => $this->telegram->saveCover($post),
            'status' => ModerationStatus::Pending,
        ]);

        return [$announcement, true];
    }
}
