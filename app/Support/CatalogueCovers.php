<?php

namespace App\Support;

use App\Enums\ModerationStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A few recent covers per author or publisher, for the cover strip on their
 * cards — one query for a whole page of cards, instead of loading every book
 * each of them has.
 */
class CatalogueCovers
{
    private const PER_CARD = 4;

    /**
     * @param  list<int>  $authorIds
     * @return array<int, list<string>> author id => cover URLs
     */
    public static function forAuthors(array $authorIds): array
    {
        return self::collect(
            self::publishedCovers()
                ->join('book_author', 'book_author.book_id', '=', 'editions.book_id')
                ->whereIn('book_author.author_id', $authorIds)
                ->select('book_author.author_id as owner_id', 'editions.book_id', 'editions.cover_path'),
        );
    }

    /**
     * @param  list<int>  $publisherIds
     * @return array<int, list<string>> publisher id => cover URLs
     */
    public static function forPublishers(array $publisherIds): array
    {
        return self::collect(
            self::publishedCovers()
                ->whereIn('editions.publisher_id', $publisherIds)
                ->select('editions.publisher_id as owner_id', 'editions.book_id', 'editions.cover_path'),
        );
    }

    private static function publishedCovers(): Builder
    {
        return DB::table('editions')
            ->join('books', 'books.id', '=', 'editions.book_id')
            ->where('books.status', ModerationStatus::Approved->value)
            ->where('editions.status', ModerationStatus::Approved->value)
            ->whereNotNull('editions.cover_path')
            ->orderByDesc('editions.year')
            ->orderByDesc('editions.id');
    }

    /** @return array<int, list<string>> */
    private static function collect(Builder $query): array
    {
        $covers = [];
        $seen = [];

        foreach ($query->get() as $row) {
            // One cover per book: two editions of it are not two books.
            if (isset($seen[$row->owner_id][$row->book_id]) || count($covers[$row->owner_id] ?? []) >= self::PER_CARD) {
                continue;
            }

            $seen[$row->owner_id][$row->book_id] = true;
            $covers[$row->owner_id][] = '/storage/'.$row->cover_path;
        }

        return $covers;
    }
}
