<?php

namespace App\Observers;

use App\Models\Book;
use App\Models\LanguagePair;
use App\Models\SocialLink;
use App\Models\Taqrizchi;
use App\Models\Translator;
use App\Services\SiteRevalidator;

/**
 * Books, language pairs and social links all render inside somebody's public
 * page (and feed its structured data and meta description), so a write to any
 * of them makes that page stale. One observer covers all three because the
 * handling is the same: rebuild whatever owns the row.
 *
 * "Whatever owns it" is no longer always a translator. Social links belong to
 * a translator or a taqrizchi, and a book may belong to no one — it still has
 * its own page, which is what gets rebuilt in that case.
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
        if ($model instanceof SocialLink) {
            $owner = $model->owner;

            match (true) {
                $owner instanceof Translator => $this->revalidator->translator($owner->slug),
                $owner instanceof Taqrizchi => $this->revalidator->taqrizchi($owner->slug),
                // An orphaned link owns no page worth naming; drop the
                // collections and let the ISR window handle the rest.
                default => $this->revalidator->everything(),
            };

            return;
        }

        if ($model instanceof Book) {
            $this->revalidator->book($model->id);
        }

        // A book with no translator still belongs in the catalogue listings,
        // and translator(null) drops those collection tags.
        $this->revalidator->translator($model->translator?->slug);
    }
}
