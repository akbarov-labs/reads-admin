<?php

namespace App\Observers;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Services\SiteRevalidator;

/**
 * A collection shows on its own page, in the list of them and on the home
 * page, so any change drops the site's collection tags. Its items too: the
 * panel saves them after the collection, so the collection's own ping can
 * come before they are in.
 */
class CollectionObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(Collection|CollectionItem $model): void
    {
        $this->revalidator->translator(null);
    }

    public function deleted(Collection|CollectionItem $model): void
    {
        $this->revalidator->translator(null);
    }
}
