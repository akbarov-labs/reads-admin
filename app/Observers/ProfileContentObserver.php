<?php

namespace App\Observers;

use App\Models\Book;
use App\Models\LanguagePair;
use App\Models\SocialLink;
use App\Services\SiteRevalidator;

/**
 * Books, language pairs and social links all render inside a translator's
 * page (and feed its structured data and meta description), so a write to
 * any of them makes that page stale. One observer covers all three because
 * the handling is identical: rebuild the owning profile.
 */
class ProfileContentObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(Book|LanguagePair|SocialLink $model): void
    {
        $this->revalidate($model);
    }

    public function deleted(Book|LanguagePair|SocialLink $model): void
    {
        $this->revalidate($model);
    }

    private function revalidate(Book|LanguagePair|SocialLink $model): void
    {
        $this->revalidator->translator($model->translator?->slug);
    }
}
