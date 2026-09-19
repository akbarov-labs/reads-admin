<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Translator = 'translator';
    case Taqrizchi = 'taqrizchi';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Translator => 'Translator',
            self::Taqrizchi => 'Taqrizchi',
        };
    }
}
