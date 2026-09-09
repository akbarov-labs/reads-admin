<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BudgetRange: string implements HasLabel
{
    case Under100 = 'under_100';
    case Between100And500 = '100_500';
    case Between500And1000 = '500_1000';
    case Over1000 = 'over_1000';
    case NotSure = 'not_sure';

    public function getLabel(): string
    {
        return match ($this) {
            self::Under100 => 'Under $100',
            self::Between100And500 => '$100 – $500',
            self::Between500And1000 => '$500 – $1,000',
            self::Over1000 => 'Over $1,000',
            self::NotSure => 'Not sure yet',
        };
    }
}
