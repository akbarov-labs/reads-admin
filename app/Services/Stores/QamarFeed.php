<?php

namespace App\Services\Stores;

use App\Models\StoreListing;
use App\Services\CatalogueSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads qamar.uz the way a slow, polite crawler would: its sitemap, then
 * the book pages that are new or changed since the last read, one at a
 * time with a pause between them. Each book page carries a JSON-LD Book
 * (ISBN, cover, author, publisher, pages, language, price), which is all
 * that is kept; the page's own text, its description included, is not.
 *
 * Any failure (an error status, a 429, no answer) ends the run: nothing is
 * retried in a loop, and the next night starts where this one stopped.
 */
class QamarFeed
{
    public const STORE = 'qamar';

    private ?float $lastRequestAt = null;

    /**
     * Book pages in the sitemap, with the sitemap's date for each.
     *
     * @return array<string, ?CarbonImmutable> url => lastmod
     */
    public function sitemap(): array
    {
        return self::parseSitemap($this->get(config('services.qamar.sitemap'))->body());
    }

    /**
     * Read the due pages, at most `pages_per_run` of them, and save what
     * each says. Stops early, with what it read so far kept, on a failure.
     *
     * Due first: pages new or changed since they were last read. Then, with
     * what is left of the run, pages read longest ago (and at least
     * `refresh_after_hours` ago), since a price or "in stock" can change
     * without the sitemap's date moving. A book that left the sitemap is
     * no longer sold there.
     *
     * @param  array<string, ?CarbonImmutable>  $sitemap
     * @return array{due: int, read: int, books: int, refreshed: int, stopped: ?string}
     */
    public function read(array $sitemap, ?int $limit = null): array
    {
        $known = StoreListing::where('store', self::STORE)->pluck('fetched_at', 'url');
        $due = [];

        foreach ($sitemap as $url => $lastmod) {
            $fetched = isset($known[$url]) ? CarbonImmutable::parse($known[$url]) : null;

            if ($fetched === null || ($lastmod !== null && $lastmod->greaterThan($fetched))) {
                $due[] = $url;
            }
        }

        if ($sitemap !== []) {
            StoreListing::where('store', self::STORE)
                ->whereNotIn('url', array_keys($sitemap))
                ->where(fn ($query) => $query->whereNull('in_stock')->orWhere('in_stock', true))
                ->update(['in_stock' => false, 'stock_left' => null]);
        }

        $limit ??= (int) config('services.qamar.pages_per_run');
        $limit = max(0, $limit);
        $refreshBefore = now()->subHours(max(1, (int) config('services.qamar.refresh_after_hours')));
        $stale = $known
            ->filter(fn ($fetched, $url) => isset($sitemap[$url]) && $fetched !== null && CarbonImmutable::parse($fetched)->lessThan($refreshBefore))
            ->sort()
            ->keys()
            ->diff($due)
            ->take(max(0, $limit - count($due)))
            ->all();

        $read = 0;
        $books = 0;
        $refreshed = 0;
        $stopped = null;

        foreach ([...array_slice($due, 0, $limit), ...$stale] as $i => $url) {
            try {
                $html = $this->get($url)->body();
            } catch (RuntimeException $e) {
                $stopped = $e->getMessage();
                break;
            }

            $read++;
            $book = self::parseBook($html);

            StoreListing::updateOrCreate(['url' => $url], [
                'store' => self::STORE,
                ...($book ?? []),
                'lastmod' => $sitemap[$url],
                'fetched_at' => now(),
            ]);

            $books += $book !== null ? 1 : 0;
            $refreshed += $i >= min(count($due), $limit) ? 1 : 0;
        }

        return ['due' => count($due), 'read' => $read, 'books' => $books, 'refreshed' => $refreshed, 'stopped' => $stopped];
    }

    /** @throws RuntimeException on anything but a 2xx answer */
    public function get(string $url): Response
    {
        $this->wait();

        try {
            $response = Http::withUserAgent(config('services.qamar.user_agent'))
                ->connectTimeout(10)
                ->timeout(30)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Qamar: no answer from {$url}");
        } finally {
            $this->lastRequestAt = microtime(true);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Qamar: HTTP {$response->status()} for {$url}");
        }

        return $response;
    }

    /**
     * Book pages (/kitob/…) of a sitemap; author, publisher and catalogue
     * pages are left out.
     *
     * @return array<string, ?CarbonImmutable>
     */
    public static function parseSitemap(string $xml): array
    {
        $pages = [];

        preg_match_all('~<url>(.*?)</url>~s', $xml, $entries);

        foreach ($entries[1] as $entry) {
            if (! preg_match('~<loc>\s*(https://qamar\.uz/kitob/[^<\s]+)\s*</loc>~', $entry, $loc)) {
                continue;
            }

            preg_match('~<lastmod>\s*([^<\s]+)\s*</lastmod>~', $entry, $lastmod);

            try {
                $date = isset($lastmod[1]) ? CarbonImmutable::parse($lastmod[1]) : null;
            } catch (\Throwable) {
                $date = null;
            }

            $pages[html_entity_decode($loc[1])] = $date;
        }

        return $pages;
    }

    /**
     * The JSON-LD Book on a product page, as listing columns, or null when
     * the page has none (a page that stopped being a book).
     *
     * @return array{isbn: ?string, title: ?string, author: ?string, publisher: ?string, pages: ?int, language: ?string, price: ?int, in_stock: ?bool, image_url: ?string}|null
     */
    public static function parseBook(string $html): ?array
    {
        preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~si', $html, $blocks);

        foreach ($blocks[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json)), true);

            if (! is_array($data)) {
                continue;
            }

            foreach (isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data] as $node) {
                $types = (array) ($node['@type'] ?? []);

                if (is_array($node) && in_array('Book', $types, true)) {
                    return self::bookColumns($node);
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $node */
    private static function bookColumns(array $node): array
    {
        $text = function (mixed $value): ?string {
            if (is_array($value)) {
                $value = $value['name'] ?? ($value[0]['name'] ?? ($value[0] ?? null));
            }

            $value = is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value)) : '';

            return $value === '' ? null : mb_substr($value, 0, 255);
        };

        $image = $node['image'] ?? null;
        $image = is_array($image) ? ($image['url'] ?? ($image[0] ?? null)) : $image;
        $offer = $node['offers'] ?? [];
        $offer = isset($offer[0]) ? $offer[0] : $offer;
        $price = is_array($offer) && is_numeric($offer['price'] ?? null) ? (int) round((float) $offer['price']) : null;
        $pages = is_numeric($node['numberOfPages'] ?? null) ? (int) $node['numberOfPages'] : null;

        return [
            'isbn' => is_scalar($node['isbn'] ?? null) ? CatalogueSync::normalizeIsbn((string) $node['isbn']) : null,
            'title' => $text($node['name'] ?? null),
            'author' => $text($node['author'] ?? null),
            'publisher' => $text($node['publisher'] ?? null),
            'pages' => $pages !== null && $pages > 0 && $pages < 65535 ? $pages : null,
            'language' => is_string($node['inLanguage'] ?? null) ? mb_substr(trim($node['inLanguage']), 0, 16) : null,
            'price' => $price !== null && $price >= 0 ? $price : null,
            'in_stock' => is_array($offer) ? self::inStock($offer['availability'] ?? null) : null,
            'image_url' => is_string($image) && str_starts_with($image, 'https://') ? mb_substr($image, 0, 1000) : null,
        ];
    }

    /**
     * A schema.org availability ("https://schema.org/InStock", "InStock",
     * "OutOfStock" …) as in stock or not, or null when it says neither.
     */
    public static function inStock(mixed $availability): ?bool
    {
        if (! is_string($availability) || trim($availability) === '') {
            return null;
        }

        $value = strtolower(basename(trim($availability)));

        return match ($value) {
            'instock', 'limitedavailability', 'instoreonly', 'onlineonly' => true,
            'outofstock', 'soldout', 'discontinued' => false,
            default => null,
        };
    }

    private function wait(): void
    {
        if ($this->lastRequestAt === null) {
            return;
        }

        $left = config('services.qamar.delay_ms') / 1000 - (microtime(true) - $this->lastRequestAt);

        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }
}
