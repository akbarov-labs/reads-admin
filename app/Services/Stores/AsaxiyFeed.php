<?php

namespace App\Services\Stores;

use App\Models\Edition;
use App\Models\StoreListing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Price and stock on asaxiy.uz, for the editions that came from there.
 *
 * Asaxiy's pages carry no ISBN, so nothing is matched by title: an edition
 * imported from Asaxiy kept its page as source_url, and only those pages
 * are read. Each carries a JSON-LD Product with an Offer (price, in stock
 * or not), and "So'nggi N ta qoldi" when only a few are left. Nothing else
 * on the page is kept, and the catalogue itself is never changed.
 *
 * Read as slowly as Qamar: one page at a time with a pause, the pages
 * never read or read longest ago first, and any failure but a missing page
 * ends the run (the next night goes on from there). A page that is gone
 * means the store no longer sells the book.
 */
class AsaxiyFeed
{
    public const STORE = 'asaxiy';

    private ?float $lastRequestAt = null;

    /**
     * Asaxiy pages of approved editions due a read: never read, or read at
     * least `refresh_after_hours` ago, the oldest first.
     *
     * @return Collection<int, Edition>
     */
    public function due(int $limit): Collection
    {
        $before = now()->subHours(max(1, (int) config('services.asaxiy.refresh_after_hours')));

        return Edition::approved()
            ->where('editions.source_url', 'like', 'https://asaxiy.uz/%')
            ->leftJoin('store_listings', 'store_listings.url', '=', 'editions.source_url')
            ->where(fn ($query) => $query->whereNull('store_listings.fetched_at')->orWhere('store_listings.fetched_at', '<', $before))
            ->orderByRaw('store_listings.fetched_at IS NOT NULL, store_listings.fetched_at, editions.id')
            ->select('editions.id', 'editions.isbn', 'editions.title', 'editions.source_url')
            ->limit(max(0, $limit))
            ->get()
            ->unique('source_url')
            ->values();
    }

    /**
     * Read up to `pages_per_run` due pages and save what each says.
     *
     * @return array{due: int, read: int, in_stock: int, sold_out: int, gone: int, stopped: ?string}
     */
    public function read(?int $limit = null): array
    {
        $editions = $this->due($limit ?? (int) config('services.asaxiy.pages_per_run'));
        $counts = ['due' => $editions->count(), 'read' => 0, 'in_stock' => 0, 'sold_out' => 0, 'gone' => 0, 'stopped' => null];

        foreach ($editions as $edition) {
            try {
                $response = $this->get($edition->source_url);
            } catch (RuntimeException $e) {
                $counts['stopped'] = $e->getMessage();
                break;
            }

            $counts['read']++;

            if ($response === null) {
                $offer = ['price' => null, 'in_stock' => false, 'stock_left' => null];
                $counts['gone']++;
            } else {
                $offer = self::parseOffer($response->body()) ?? ['price' => null, 'in_stock' => null, 'stock_left' => null];

                if ($offer['in_stock'] !== null) {
                    $counts[$offer['in_stock'] ? 'in_stock' : 'sold_out']++;
                }
            }

            StoreListing::updateOrCreate(['url' => $edition->source_url], [
                'store' => self::STORE,
                'isbn' => $edition->isbn,
                'title' => $edition->title !== null ? mb_substr($edition->title, 0, 255) : null,
                'edition_id' => $edition->id,
                ...$offer,
                'fetched_at' => now(),
                'applied_at' => now(),
            ]);
        }

        return $counts;
    }

    /**
     * The Offer on a product page: price in so'm, in stock or not, and how
     * many are left when the page says ("So'nggi 3 ta qoldi"). Null when
     * the page has no JSON-LD Product.
     *
     * @return array{price: ?int, in_stock: ?bool, stock_left: ?int}|null
     */
    public static function parseOffer(string $html): ?array
    {
        preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~si', $html, $blocks);

        foreach ($blocks[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json)), true);

            if (! is_array($data)) {
                continue;
            }

            foreach (isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data] as $node) {
                if (! is_array($node) || ! in_array('Product', (array) ($node['@type'] ?? []), true)) {
                    continue;
                }

                $offer = $node['offers'] ?? [];
                $offer = is_array($offer) && isset($offer[0]) ? $offer[0] : $offer;
                $price = is_array($offer) && is_numeric($offer['price'] ?? null) ? (int) round((float) $offer['price']) : null;
                $inStock = is_array($offer) ? QamarFeed::inStock($offer['availability'] ?? null) : null;
                $left = null;

                // On the raw page: strip_tags loses text after the page's scripts.
                if ($inStock !== false && preg_match("~So(?:[‘'ʻ’`]|&#039;|&#8216;)?nggi\s+(\d{1,4})\s+ta\s+qoldi~u", $html, $match)) {
                    $left = (int) $match[1];
                }

                return [
                    'price' => $price !== null && $price > 0 ? $price : null,
                    'in_stock' => $inStock,
                    'stock_left' => $left,
                ];
            }
        }

        return null;
    }

    /**
     * The page, or null when it is gone (404, 410).
     *
     * @throws RuntimeException on any other answer but a 2xx, or none
     */
    private function get(string $url): ?Response
    {
        $this->wait();

        try {
            $response = Http::withUserAgent(config('services.asaxiy.user_agent'))
                ->connectTimeout(10)
                ->timeout(30)
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException("Asaxiy: no answer from {$url}");
        } finally {
            $this->lastRequestAt = microtime(true);
        }

        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Asaxiy: HTTP {$response->status()} for {$url}");
        }

        return $response;
    }

    private function wait(): void
    {
        if ($this->lastRequestAt === null) {
            return;
        }

        $left = config('services.asaxiy.delay_ms') / 1000 - (microtime(true) - $this->lastRequestAt);

        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
        }
    }
}
