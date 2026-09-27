<?php

namespace App\Observers;

use App\Models\Book;
use App\Models\Edition;
use App\Models\LanguagePair;
use App\Models\SocialLink;
use App\Models\Taqrizchi;
use App\Models\Translator;
use App\Services\SiteRevalidator;

/**
 * Books, editions, language pairs and social links all render inside
 * somebody's public page (and feed its structured data and meta
 * description), so a write to any of them makes that page stale. One
 * observer covers them because the handling is the same: rebuild whatever
 * owns the row.
 *
 * "Whatever owns it" is no longer always a translator. Social links belong to
 * a translator or a taqrizchi; a book belongs to no one but has its own page;
 * an edition sits on its book's page and on the profile of everyone who
 * translated it.
 */
class ProfileContentObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(Book|Edition|LanguagePair|SocialLink $model): void
    {
        $this->revalidate($model);
    }

    public function deleted(Book|Edition|LanguagePair|SocialLink $model): void
    {
        $this->revalidate($model);
    }

    private function revalidate(Book|Edition|LanguagePair|SocialLink $model): void
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

        if ($model instanceof Edition) {
            $this->revalidator->book($model->book_id);

            foreach ($model->translators()->pluck('slug') as $slug) {
                $this->revalidator->translator($slug);
            }
        }

        if ($model instanceof Book) {
            $this->revalidator->book($model->id);
        }

        // translator(null) drops the collection tags, which every catalogue
        // listing hangs off; a language pair names its translator directly.
        $this->revalidator->translator($model instanceof LanguagePair ? $model->translator?->slug : null);
    }
}
