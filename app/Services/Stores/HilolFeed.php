<?php

namespace App\Services\Stores;

use App\Models\Edition;
use App\Models\StoreListing;
use App\Services\CatalogueSync;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Price and stock on hilolnashr.uz, Hilol-Nashr's own shop (it sells other
 * publishers' books too).
 *
 * The shop has no sitemap, so each night walks its "Kitoblar" listing page
 * by page, one page at a time with a pause. A listing page names each
 * book's page, its price and whether it is sold out, which is all a night
 * needs for every book at once. A book's own page is read only once, for
 * its ISBN, which is how it is matched with the catalogue. The page's text
 * is not kept and the catalogue itself is never changed.
 *
 * Any failure ends the run with what was read so far kept. A book missing
 * from a listing walked to its end is no longer sold.
 */
class HilolFeed
{
    /** The store's key, as the site names it (lib/stores.ts). */
    public const STORE = 'hilol-nashr';

    private ?float $lastRequestAt = null;

    /**
     * @return array{pages: int, books: int, complete: bool, isbn_read: int, matched: int, stopped: ?string}
     */
    public function read(?int $listingPages = null, ?int $bookPages = null): array
    {
        $counts = ['pages' => 0, 'books' => 0, 'complete' => false, 'isbn_read' => 0, 'matched' => 0, 'stopped' => null];
        $listingPages ??= (int) config('services.hilol.listing_pages_per_run');
        $bookPages ??= (int) config('services.hilol.book_pages_per_run');
        $started = now();

        try {
            $last = null;

            for ($page = 1; $page <= $listingPages; $page++) {
                $html = $this->get(config('services.hilol.listing').'&page='.$page)->body();
                $books = self::parseListing($html);
                $last ??= self::pageCount($html);
                $counts['pages']++;

                if ($books === []) {
                    $counts['complete'] = true;
                    break;
                }

                foreach ($books as $book) {
                    StoreListing::updateOrCreate(['url' => $book['url']], [
                        'store' => self::STORE,
                        'title' => $book['title'],
                        'price' => $book['price'],
                        'in_stock' => $book['in_stock'],
                        'stock_left' => null,
                        'fetched_at' => now(),
                    ]);
                    $counts['books']++;
                }

                if ($last !== null && $page >= $last) {
                    $counts['complete'] = true;
                    break;
                }
            }

            if ($counts['complete']) {
                StoreListing::where('store', self::STORE)
                    ->where('fetched_at', '<', $started)
                    ->where(fn ($query) => $query->whereNull('in_stock')->orWhere('in_stock', true))
                    ->update(['in_stock' => false]);
            }

            // Each book's own page once, for its ISBN.
            $unread = StoreListing::where('store', self::STORE)->whereNull('applied_at')->orderBy('id')->limit(max(0, $bookPages))->get();

            foreach ($unread as $listing) {
                $isbn = self::parseIsbn($this->get($listing->url)->body());
                $listing->forceFill(['isbn' => $isbn, 'applied_at' => now()])->save();
                $counts['isbn_read']++;
            }
        } catch (RuntimeException $e) {
            $counts['stopped'] = $e->getMessage();
        }

        // Books with an ISBN and no edition yet: the catalogue may have it now.
        StoreListing::where('store', self::STORE)
            ->whereNotNull('isbn')
            ->whereNull('edition_id')
            ->get()
            ->each(function (StoreListing $listing) use (&$counts) {
                $edition = Edition::where('isbn', $listing->isbn)->value('id');

                if ($edition !== null) {
                    $listing->forceFill(['edition_id' => $edition])->save();
                    $counts['matched']++;
                }
            });

        return $counts;
    }

    /**
     * The books on a listing page's main grid (not the side modules), each
     * with its page, title, price in so'm and whether it is in stock.
     *
     * @return list<array{url: string, title: ?string, price: ?int, in_stock: bool}>
     */
    public static function parseListing(string $html): array
    {
        $start = strpos($html, 'class="main-products');

        if ($start === false) {
            return [];
        }

        // Past the last page the shop still answers, with no grid of its
        // own: only the side modules, and no pagination under them.
        $end = strpos($html, 'class="row pagination-results"', $start);

        if ($end === false) {
            return [];
        }

        $grid = substr($html, $start, $end - $start);

        preg_match_all('~<div class="product-layout([^"]*)".*?<div class="name">\s*<a href="([^"]+)"[^>]*>(.*?)</a>.*?<div class="price">(.*?)</div>\s*</div>~s', $grid, $items, PREG_SET_ORDER);

        $books = [];

        foreach ($items as [, $classes, $url, $title, $price]) {
            $url = html_entity_decode($url);

            if (! str_starts_with($url, 'https://hilolnashr.uz/')) {
                continue;
            }

            // A special price shows the new one beside the old: the new is what it costs.
            $amount = preg_match('~price-new[^>]*>([^<]+)<~', $price, $new) ? $new[1] : strip_tags($price);
            $digits = preg_replace('/\D/', '', html_entity_decode($amount));
            $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($title))));

            $books[] = [
                'url' => mb_substr($url, 0, 255),
                'title' => $title === '' ? null : mb_substr($title, 0, 255),
                'price' => $digits !== '' && (int) $digits > 0 ? (int) $digits : null,
                'in_stock' => ! str_contains($classes, 'out-of-stock'),
            ];
        }

        return $books;
    }

    /** How many listing pages there are ("Жами 3493 та (292 саҳифада)"), or null. */
    public static function pageCount(string $html): ?int
    {
        return preg_match('~\((\d+)\s*(?:саҳифада|sahifada|страниц|pages?)\)~iu', self::text($html), $match) ? (int) $match[1] : null;
    }

    /** A page's text: scripts and styles out, tags turned to spaces (strip_tags loses text on these pages). */
    private static function text(string $html): string
    {
        $html = preg_replace('~<(script|style)\b.*?</\1>~si', ' ', $html) ?? $html;

        return html_entity_decode(preg_replace('~<[^>]+>~', ' ', $html) ?? $html);
    }

    /** The ISBN a book page gives ("ISBN: 978-9910-09-968-7"), or null. */
    public static function parseIsbn(string $html): ?string
    {
        $text = self::text($html);

        foreach (preg_match_all('~ISBN\s*:?\s*([0-9][0-9\-]{11,16}[0-9Xx])~u', $text, $matches) ? $matches[1] : [] as $candidate) {
            if (($isbn = CatalogueSync::normalizeIsbn($candidate)) !== null) {
                return $isbn;
            }
        }

        return null;
    }

    /** @throws RuntimeException on anything but a 2xx answer */
    private function get(string $url): Response
    {
        $this->wait();

        try {
            $response = Http::withUserAgent(config('services.hilol.user_agent'))
                ->connectTimeout(10)
                ->timeout(60)
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException("Hilol: no answer from {$url}");
        } finally {
            $this->lastRequestAt = microtime(true);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Hilol: HTTP {$response->status()} for {$url}");
        }

        return $response;
    }

    private function wait(): void
    {
        if ($this->lastRequestAt === null) {
            return;
        }

        $left = config('services.hilol.delay_ms') / 1000 - (microtime(true) - $this->lastRequestAt);

        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }
}
