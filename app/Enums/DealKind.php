<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What a deal offers a reader. */
enum DealKind: string implements HasLabel
{
    /** Books for less: a sale, a percentage off, a promo code. */
    case Discount = 'discount';

    /** Books to win: a giveaway or a contest with books as prizes. */
    case Contest = 'contest';

    public function getLabel(): string
    {
        return match ($this) {
            self::Discount => 'Discount',
            self::Contest => 'Contest',
        };
    }
}
