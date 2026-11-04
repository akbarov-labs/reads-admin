<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where an outbound click was headed. */
enum OutboundClickKind: string implements HasLabel
{
    /** A store's own search for a book's title. */
    case Store = 'store';

    /** A publisher's Telegram contact, from an announcement's order button. */
    case PublisherOrder = 'publisher_order';

    /**
     * Google's AI Mode, asked about a page's subject from its ask-AI
     * button. The target is the kind of page: one of ASK_AI_SUBJECTS.
     */
    case AskAi = 'ask_ai';

    /**
     * The Kitoblarim bot, opened at a book's review from the "Taqriz
     * yozish" button on its page. Always has a book; its target is
     * always BOT_TARGET.
     */
    case BotReview = 'bot_review';

    /** The one place a review button leads. */
    public const BOT_TARGET = 'bot';

    /** The pages that carry an ask-AI button. */
    public const ASK_AI_SUBJECTS = ['book', 'author', 'translator', 'publisher'];

    public function getLabel(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::PublisherOrder => 'Publisher order',
            self::AskAi => 'Ask AI',
            self::BotReview => 'Write a review',
        };
    }
}
