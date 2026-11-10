<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;

/** One post as a public channel's web preview (t.me/s/<channel>) shows it. */
final class TelegramPost
{
    public function __construct(
        public readonly string $channel,
        public readonly int $id,
        public readonly ?string $text,
        /** The first photo, or a video's thumbnail. */
        public readonly ?string $photoUrl,
        public readonly ?CarbonImmutable $postedAt,
        /** A photo, not only a video's thumbnail: publishers show a new book's cover. */
        public readonly bool $hasPhoto = false,
        /**
         * Where a forwarded post came from: that channel's username, '' when
         * the source is hidden, null when the channel wrote the post itself.
         */
        public readonly ?string $forwardedFrom = null,
    ) {}

    /** Forwarded from somewhere else than its own channel (its own older post is not "else"). */
    public function isForwardedFromElsewhere(): bool
    {
        return $this->forwardedFrom !== null && strcasecmp($this->forwardedFrom, $this->channel) !== 0;
    }

    public function url(): string
    {
        return "https://t.me/{$this->channel}/{$this->id}";
    }
}
