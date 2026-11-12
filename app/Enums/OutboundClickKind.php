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

    /**
     * A book's page on the site, opened from the monthly list of books a
     * Kitoblarim reader shares in Telegram. Always has a book; its target
     * is always LIST_TARGET.
     */
    case SharedList = 'shared_list';

    /**
     * A deal's button on the site's Chegirmalar page: the shop's page or
     * the post. Its target is Deal::clickTarget(), "deal-<id>".
     */
    case Deal = 'deal';

    /** The one place a review button leads. */
    public const BOT_TARGET = 'bot';

    /** What a shared list's click records as its target: it leaves for no other site. */
    public const LIST_TARGET = 'list';

    /** The pages that carry an ask-AI button. */
    public const ASK_AI_SUBJECTS = ['book', 'author', 'translator', 'publisher'];

    public function getLabel(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::PublisherOrder => 'Publisher order',
            self::AskAi => 'Ask AI',
            self::BotReview => 'Write a review',
            self::SharedList => 'Shared reading list',
            self::Deal => 'Deal',
        };
    }
}
