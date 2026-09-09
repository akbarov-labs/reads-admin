<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProjectType: string implements HasLabel
{
    case Book = 'book';
    case Article = 'article';
    case CertifiedDocument = 'certified_document';
    case Interpretation = 'interpretation';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Book => 'Book',
            self::Article => 'Article',
            self::CertifiedDocument => 'Certified document',
            self::Interpretation => 'Interpretation',
            self::Other => 'Other',
        };
    }
}
