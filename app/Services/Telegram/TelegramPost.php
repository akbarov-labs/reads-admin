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
    ) {}

    public function url(): string
    {
        return "https://t.me/{$this->channel}/{$this->id}";
    }
}
