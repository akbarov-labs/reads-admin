<?php

namespace App\Console\Commands;

use App\Services\SiteRevalidator;
use App\Services\Stores\HilolFeed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stores:hilol
    {--listing-pages= : Read at most this many listing pages (default: services.hilol.listing_pages_per_run)}
    {--book-pages= : Read at most this many book pages for ISBNs (default: services.hilol.book_pages_per_run)}')]
#[Description('Read price and stock from the hilolnashr.uz listing, and ISBNs from book pages not read yet')]
class ReadHilol extends Command
{
    public function handle(HilolFeed $feed): int
    {
        $option = fn (string $name) => $this->option($name) !== null ? (int) $this->option($name) : null;
        $read = $feed->read($option('listing-pages'), $option('book-pages'));

        $this->line(sprintf(
            'Listing: %d pages, %d books%s. Book pages read for ISBN: %d. Newly matched with the catalogue: %d.',
            $read['pages'], $read['books'], $read['complete'] ? ' (to its end)' : '', $read['isbn_read'], $read['matched'],
        ));

        if ($read['stopped'] !== null) {
            $this->warn("Stopped early: {$read['stopped']}");
        }

        if ($read['books'] > 0 || $read['matched'] > 0) {
            // Prices and "in stock" show on book pages.
            app(SiteRevalidator::class)->everything();
        }

        return self::SUCCESS;
    }
}
