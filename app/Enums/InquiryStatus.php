<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InquiryStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Replied = 'replied';
    case Archived = 'archived';
    case Spam = 'spam';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Replied => 'Replied',
            self::Archived => 'Archived',
            self::Spam => 'Spam',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Replied => 'success',
            self::Archived => 'gray',
            self::Spam => 'danger',
        };
    }
}
