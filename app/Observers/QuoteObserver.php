<?php

namespace App\Observers;

use App\Models\Quote;
use App\Services\SiteRevalidator;

/**
 * A quote shows on its book's page, and any of them may be today's on the
 * home page, so a change rebuilds both.
 */
class QuoteObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(Quote $quote): void
    {
        $this->revalidate($quote);
    }

    public function deleted(Quote $quote): void
    {
        $this->revalidate($quote);
    }

    private function revalidate(Quote $quote): void
    {
        $this->revalidator->book($quote->book_id);

        if ($quote->wasChanged('book_id') && $quote->getOriginal('book_id')) {
            $this->revalidator->book($quote->getOriginal('book_id'));
        }

        // translator(null) drops the collection tags the home page hangs off.
        $this->revalidator->translator(null);
    }
}
