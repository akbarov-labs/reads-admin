<?php

namespace App\Http\Resources\Api;

use App\Models\Book;
use App\Models\StoreListing;
use App\Support\UzbekText;
use Illuminate\Http\Request;

/**
 * A book's own page: the card fields, plus every approved edition, the
 * approved taqrizlar of all of them, and what to read next.
 *
 * @mixin Book
 */
class BookDetailResource extends BookResource
{
    /** @param  iterable<Book>  $related */
    public function __construct(Book $book, private readonly iterable $related = [])
    {
        parent::__construct($book);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            // Its own, or the fullest an edition carries (Book::getAboutAttribute).
            'description' => $this->about,
            // Credit for a description copied from a shop listing, or null.
            'descriptionSource' => $this->about_source,
            // Whether `description` is the editors' own words; a copy from a
            // shop or a publisher channel isn't, and the site keeps those out
            // of search snippets.
            'descriptionIsOwn' => filled($this->description),
            // A shop's text the page folds under the book's own, or null.
            'shopDescription' => $this->shop_about,
            'editions' => EditionResource::collection($this->approvedEditions),

            // Approved only — the controller constrains the eager load, so
            // pending rows never reach the resource.
            'taqrizlar' => TaqrizResource::collection($this->whenLoaded('taqrizlar')),

            // The book's headline score: the mean of each review's own
            // average. Averaging the reviews rather than pooling every raw
            // aspect keeps one thorough reviewer from outweighing three
            // brief ones simply for having filled in more fields.
            'averageScore' => $this->when($this->relationLoaded('taqrizlar'), fn () => $this->averageOfTaqrizlar()),

            // Kitoblarim bot readers: their stars over every approved
            // edition, anonymous, and the reviews a moderator approved.
            'readerRating' => $this->readerRating(),
            // How many of them want to read it, are reading it, have read it.
            'readers' => $this->readers(),
            'readerReviews' => ReaderReviewResource::collection($this->whenLoaded('readerReviews')),
            // Published lines from the book, newest first.
            'quotes' => QuoteResource::collection($this->whenLoaded('quotes')),

            'relatedBooks' => BookResource::collection(collect($this->related)),

            // Published libraries that hold it: those with a copy on the
            // shelf first, then the most recently confirmed lists.
            'libraries' => $this->whenLoaded('libraryHoldings', fn () => $this->libraries()),

            // Stores whose own page for one of its editions was read lately:
            // price and whether they had it, one row per store.
            'stores' => $this->stores(),
        ]);
    }

    /** A store read longer ago than this is not shown: its stock is a guess by then. */
    public const STORE_SHOWN_DAYS = 10;

    /** Store keys as the site names them (lib/stores.ts), and how each is written. */
    private const STORE_NAMES = ['qamar' => 'Qamar', 'asaxiy' => 'Asaxiy', 'hilol-nashr' => 'Hilol-nashr'];

    /** Stores that are a publisher's own shop, and that publisher's name. */
    private const PUBLISHER_SHOPS = ['hilol-nashr' => 'Hilol-Nashr'];

    /**
     * One row per store: of its pages for this book's editions, the one
     * it has in stock, then the cheapest, then the latest read. In stock
     * first, then by price.
     *
     * @return list<array<string, mixed>>
     */
    private function stores(): array
    {
        $since = now()->subDays(self::STORE_SHOWN_DAYS);

        return $this->approvedEditions
            ->flatMap(fn ($edition) => $edition->relationLoaded('storeListings') ? $edition->storeListings : [])
            ->filter(fn ($listing) => isset(self::STORE_NAMES[$listing->store])
                && $listing->fetched_at !== null && $listing->fetched_at->greaterThanOrEqualTo($since)
                && ($listing->in_stock !== null || $listing->price !== null))
            ->groupBy('store')
            ->map(fn ($listings) => $listings->sortBy([
                fn ($a, $b) => ($b->in_stock === true) <=> ($a->in_stock === true),
                fn ($a, $b) => ($a->price ?? PHP_INT_MAX) <=> ($b->price ?? PHP_INT_MAX),
                fn ($a, $b) => $b->fetched_at <=> $a->fetched_at,
            ])->first())
            ->sortBy([
                fn ($a, $b) => ($b->in_stock === true) <=> ($a->in_stock === true),
                fn ($a, $b) => ($a->price ?? PHP_INT_MAX) <=> ($b->price ?? PHP_INT_MAX),
            ])
            ->map(fn ($listing) => [
                'store' => $listing->store,
                'name' => self::STORE_NAMES[$listing->store],
                'url' => $listing->url,
                'price' => $listing->price,
                'inStock' => $listing->in_stock,
                'left' => $listing->in_stock === true ? $listing->stock_left : null,
                'checkedAt' => $listing->fetched_at->toIso8601String(),
                // The publisher's own shop selling its own book ("nashriyot").
                'fromPublisher' => $this->soldByItsPublisher($listing),
            ])
            ->values()
            ->all();
    }

    /** Whether this store is the shop of the publisher of the edition it sells. */
    private function soldByItsPublisher(StoreListing $listing): bool
    {
        $publisher = self::PUBLISHER_SHOPS[$listing->store] ?? null;
        $ours = $this->approvedEditions->firstWhere('id', $listing->edition_id)?->publisher?->name;

        return $publisher !== null && $ours !== null
            && UzbekText::publisherKey($ours) === UzbekText::publisherKey($publisher);
    }

    /** @return list<array<string, mixed>> */
    private function libraries(): array
    {
        return $this->libraryHoldings
            ->sortBy([
                fn ($a, $b) => ($b->copies_available > 0) <=> ($a->copies_available > 0),
                fn ($a, $b) => $b->library->holdings_updated_at <=> $a->library->holdings_updated_at,
            ])
            ->map(fn ($holding) => [
                'slug' => $holding->library->slug,
                'name' => $holding->library->name,
                'region' => $holding->library->region,
                'district' => $holding->library->district,
                'copiesTotal' => $holding->copies_total,
                'copiesAvailable' => $holding->copies_available,
                'updatedAt' => $holding->library->holdings_updated_at?->toIso8601String(),
                'stale' => LibraryResource::isStale($holding->library),
            ])
            ->values()
            ->all();
    }

    /** @return array{average: float, count: int, stars: list<int>|null}|null */
    private function readerRating(): ?array
    {
        $count = (int) $this->approvedEditions->sum('reader_rating_count');

        if ($count === 0) {
            return null;
        }

        // Ones first, summed over the editions that sent a split; null until one has.
        $splits = $this->approvedEditions->pluck('reader_rating_stars')->filter();

        return [
            'average' => round($this->approvedEditions->sum('reader_rating_total') / $count, 1),
            'count' => $count,
            'stars' => $splits->isEmpty() ? null : array_map(
                fn (int $star) => (int) $splits->sum(fn (array $split) => $split[$star] ?? 0),
                range(0, 4),
            ),
        ];
    }

    /** @return array{want: int, reading: int, read: int}|null */
    private function readers(): ?array
    {
        $readers = [
            'want' => (int) $this->approvedEditions->sum('reader_want_count'),
            'reading' => (int) $this->approvedEditions->sum('reader_reading_count'),
            'read' => (int) $this->approvedEditions->sum('reader_read_count'),
        ];

        return array_sum($readers) === 0 ? null : $readers;
    }

    private function averageOfTaqrizlar(): ?float
    {
        $averages = $this->taqrizlar
            ->map(fn ($taqriz) => $taqriz->average_score)
            ->filter(fn ($score) => $score !== null);

        return $averages->isEmpty() ? null : round($averages->avg(), 1);
    }
}
