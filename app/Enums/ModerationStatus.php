<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Whether a catalogue row is visible to the public site.
 *
 * Separate from TaqrizStatus even though the cases match: a book and a review
 * are moderated for different reasons and could easily diverge (a book might
 * gain a "merged into another edition" state, a review never would).
 */
enum ModerationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting review',
            self::Approved => 'Published',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }
}
