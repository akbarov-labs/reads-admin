<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where an outbound click was headed. */
enum OutboundClickKind: string implements HasLabel
{
    /** A store's own search for a book's title. */
    case Store = 'store';

    public function getLabel(): string
    {
        return match ($this) {
            self::Store => 'Store',
        };
    }
}
