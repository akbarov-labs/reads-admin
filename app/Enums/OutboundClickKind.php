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

    public function getLabel(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::PublisherOrder => 'Publisher order',
        };
    }
}
