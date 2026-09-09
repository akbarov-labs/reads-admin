<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BookRole: string implements HasLabel
{
    case Author = 'author';
    case Translator = 'translator';
    case Editor = 'editor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Author => 'Author',
            self::Translator => 'Translator',
            self::Editor => 'Editor',
        };
    }
}
