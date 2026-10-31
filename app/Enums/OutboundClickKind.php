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

    /** The pages that carry an ask-AI button. */
    public const ASK_AI_SUBJECTS = ['book', 'author', 'translator', 'publisher'];

    public function getLabel(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::PublisherOrder => 'Publisher order',
            self::AskAi => 'Ask AI',
        };
    }
}
