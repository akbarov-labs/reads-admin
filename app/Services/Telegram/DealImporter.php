<?php

namespace App\Services\Telegram;

use App\Enums\ModerationStatus;
use App\Models\Deal;
use App\Models\Publisher;

/**
 * Turns a channel post into a pending deal draft: from the hourly scan of
 * publishers' channels, or a link an admin pastes (a shop's channel too,
 * named by hand). A post already drafted is never drafted twice.
 */
class DealImporter
{
    public function __construct(private readonly TelegramChannel $telegram) {}

    /**
     * @return array{0: Deal, 1: bool} The deal, and whether it is new.
     *
     * @throws TelegramChannelException
     */
    public function import(string $link, ?Publisher $publisher = null, ?string $shopName = null): array
    {
        $parsed = TelegramChannel::parseLink($link);

        if ($parsed === null) {
            throw new TelegramChannelException('That is not a link to a post on a public channel. Copy it from the post in Telegram: Copy Post Link.');
        }

        [$channel, $id] = $parsed;
        $existing = Deal::query()->whereRaw('lower(source_url) = ?', [strtolower("https://t.me/{$channel}/{$id}")])->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $post = $this->telegram->post($channel, $id);
        $publisher ??= Publisher::query()->whereRaw('lower(telegram_channel) = ?', [strtolower($channel)])->first();

        if ($publisher === null && blank($shopName)) {
            throw new TelegramChannelException("@{$channel} is not a publisher's channel: choose the publisher, or type the shop's name.");
        }

        return [$this->create($post, $publisher, $shopName, foundByScan: false), true];
    }

    /** A scanned post's draft, or null when it was drafted before. */
    public function fromPost(TelegramPost $post, Publisher $publisher): ?Deal
    {
        if (Deal::query()->whereRaw('lower(source_url) = ?', [strtolower($post->url())])->exists()) {
            return null;
        }

        return $this->create($post, $publisher, null, foundByScan: true);
    }

    private function create(TelegramPost $post, ?Publisher $publisher, ?string $shopName, bool $foundByScan): Deal
    {
        return Deal::create([
            ...DealDraft::fromText($post->text, $post->postedAt),
            'publisher_id' => $publisher?->id,
            'shop_name' => $publisher ? null : trim((string) $shopName),
            'source_url' => $post->url(),
            'posted_at' => $post->postedAt,
            'post_text' => $post->text,
            'image_path' => $post->hasPhoto ? $this->telegram->saveCover($post) : null,
            'status' => ModerationStatus::Pending,
            'found_by_scan' => $foundByScan,
        ]);
    }
}
