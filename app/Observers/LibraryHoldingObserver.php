<?php

namespace App\Observers;

use App\Models\LibraryHolding;
use App\Services\SiteRevalidator;

/**
 * A copy lent out or returned changes two pages on the site: the book's
 * ("N ta bor" / "hammasi band") and the library's, which the library ping
 * drops along with every book page (see SiteRevalidator::library()).
 *
 * The holdings import writes many rows at once without events and pings
 * once at the end; see LibraryImporter::apply().
 */
class LibraryHoldingObserver
{
    public function __construct(private readonly SiteRevalidator $revalidator) {}

    public function saved(LibraryHolding $holding): void
    {
        $this->changed($holding);
    }

    public function deleted(LibraryHolding $holding): void
    {
        $this->changed($holding);
    }

    private function changed(LibraryHolding $holding): void
    {
        $library = $holding->library;

        if ($library === null) {
            return;
        }

        $library->touchHoldings();
        $this->revalidator->library($library->slug);
    }
}
