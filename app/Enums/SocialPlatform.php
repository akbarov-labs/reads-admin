<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SocialPlatform: string implements HasIcon, HasLabel
{
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case Youtube = 'youtube';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Website = 'website';

    public function getLabel(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Instagram => 'Instagram',
            self::Youtube => 'YouTube',
            self::X => 'X (Twitter)',
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Website => 'Website',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Telegram => 'heroicon-o-paper-airplane',
            self::Instagram => 'heroicon-o-camera',
            self::Youtube => 'heroicon-o-play-circle',
            self::X => 'heroicon-o-at-symbol',
            self::LinkedIn => 'heroicon-o-briefcase',
            self::Facebook => 'heroicon-o-thumb-up',
            self::Website => 'heroicon-o-globe-alt',
        };
    }
}
