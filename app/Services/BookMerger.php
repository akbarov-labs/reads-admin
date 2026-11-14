<?php

namespace App\Services;

use App\Enums\ModerationStatus;
use App\Models\Book;
use App\Models\BookMerge;
use App\Support\UzbekText;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Folds one book into another when the catalogue holds one work twice: the
 * Asaxiy import made a book per binding, format or script ("11/22/63",
 * "11/22/63 (A5)", "11/22/63 (мягкая)"), where the data model wants one book
 * with several editions (docs/DATA-MODEL.md).
 *
 * Everything that hangs off the duplicate moves to the kept book: its
 * editions (and with them their taqrizlar, covers, store listings and
 * reader counts), shelves, recommendations, page views, collection and
 * library entries, quotes, reader reviews, and the rows that only point at
 * it (announcements, deals, clicks, Softly and library-import rows). Then
 * the duplicate is deleted and a `book_merges` row is left behind, as
 * author_aliases is for authors:
 *
 * - its old id, so /api/books/{old id} answers with the kept book and the
 *   site redirects the old address;
 * - its first author + title key, so CatalogueRecords::findBook (the bot's
 *   push, the Asaxiy seeder) joins the kept book instead of making the
 *   duplicate again;
 * - a snapshot of everything moved, dropped or filled, so unmerge() can put
 *   it back.
 *
 * What the kept book says about itself wins: its title, its own
 * description, its authors (unless it has none) and its genre (unless it
 * has none). The duplicate's are kept in the snapshot.
 *
 * Writes go through the query builder, without model events, so a dry run
 * (a merge inside a transaction that is rolled back) never pings the site.
 *
 * Which two books are one work is a person's call, not this class's:
 * three abridgements of «Капитал» are three books.
 */
class BookMerger
{
    /** Filled on the kept book only where it has nothing. Never the title. */
    private const FILLABLE = ['original_title', 'source_language', 'description'];

    /**
     * Rows that only point at a book and have no uniqueness to respect:
     * they simply move. table => what it is, for the report.
     */
    private const POINTERS = [
        'quotes' => 'quotes',
        'reader_reviews' => 'reader reviews',
        'announcements' => 'announcements',
        'deals' => 'deals',
        'outbound_clicks' => 'store clicks',
        'softly_books' => 'Softly rows',
        'library_import_rows' => 'library import rows',
    ];

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    /**
     * What a merge would notice before it moves anything: differences a
     * person should look at, never reasons this class refuses.
     *
     * @return list<string>
     */
    public function warnings(Book $duplicate, Book $into): array
    {
        $warnings = [];

        $authors = fn (Book $book) => DB::table('book_author')->join('authors', 'authors.id', '=', 'book_author.author_id')
            ->where('book_id', $book->id)->orderBy('position')->orderBy('book_author.id')->pluck('authors.name')->all();
        [$keptAuthors, $mergedAuthors] = [$authors($into), $authors($duplicate)];

        if ($keptAuthors !== [] && $mergedAuthors !== [] && $keptAuthors !== $mergedAuthors) {
            $warnings[] = 'authors differ: kept «'.implode(', ', $keptAuthors).'», merged «'.implode(', ', $mergedAuthors).'» (the kept book\'s stay)';
        }

        if (filled($into->description) && filled($duplicate->description)) {
            $warnings[] = 'both have their own description: the merged book\'s is dropped (kept in the snapshot)';
        }

        if ($into->status !== ModerationStatus::Approved && $duplicate->status === ModerationStatus::Approved) {
            $warnings[] = "the kept book is {$into->status->value} but the merged one is approved: the page would disappear";
        }

        if ($into->series_id !== null && $duplicate->series_id !== null && $into->series_id !== $duplicate->series_id) {
            $warnings[] = 'they are in different series: the kept book\'s stays';
        }

        // "Rus tili" and "Ruscha" are one language: Asaxiy wrote both.
        $languages = fn (Book $book) => DB::table('editions')->where('book_id', $book->id)->whereNotNull('language')
            ->pluck('language')->flatMap(fn (string $label) => explode(',', $label))
            ->map(fn (string $label) => self::languageStem($label))->filter()->unique()->sort()->values()->all();
        [$keptLanguages, $mergedLanguages] = [$languages($into), $languages($duplicate)];

        if ($keptLanguages !== [] && $mergedLanguages !== [] && array_intersect($keptLanguages, $mergedLanguages) === []) {
            $warnings[] = 'no language in common: kept '.implode(', ', $keptLanguages).'; merged '.implode(', ', $mergedLanguages);
        }

        return $warnings;
    }

    /**
     * @param  bool  $revalidate  False when merging many in a row (the
     *                            caller clears the site once at the end) and
     *                            in a dry run.
     * @return array{merge_id: int, moved: array<string, int>, dropped: array<string, int>, filled: list<string>, retitled: int}
     */
    public function merge(Book $duplicate, Book $into, bool $revalidate = true): array
    {
        if ($duplicate->is($into)) {
            throw new InvalidArgumentException('A book cannot be merged into itself.');
        }

        $report = DB::transaction(function () use ($duplicate, $into) {
            // Read again inside the transaction: the models may be stale.
            $dup = DB::table('books')->where('id', $duplicate->id)->first();
            $kept = DB::table('books')->where('id', $into->id)->first();

            if ($dup === null || $kept === null) {
                throw new InvalidArgumentException("Book {$duplicate->id} or {$into->id} no longer exists.");
            }

            $now = now();
            $snapshot = ['book' => (array) $dup];
            $moved = [];
            $dropped = [];

            // Editions whose cover shows the book's title will show the kept
            // one's from now on: counted for the report.
            $retitled = UzbekText::key((string) $dup->uzbek_title) === UzbekText::key((string) $kept->uzbek_title)
                ? 0
                : DB::table('editions')->where('book_id', $dup->id)->whereNull('title')->count();

            // Touched, because the bot's feed orders by updated_at: that is
            // how it hears of their new title, authors and genre.
            $snapshot['editions'] = DB::table('editions')->where('book_id', $dup->id)->orderBy('id')->pluck('id')->all();
            DB::table('editions')->where('book_id', $dup->id)->update(['book_id' => $kept->id, 'updated_at' => $now]);
            $moved['editions'] = count($snapshot['editions']);

            $keptChanged = $this->moveAuthors($dup, $kept, $snapshot);
            $keptChanged = $this->moveShelves($dup, $kept, $snapshot) || $keptChanged;

            if ($keptChanged) {
                DB::table('editions')->where('book_id', $kept->id)->update(['updated_at' => $now]);
            }

            [$moved['recommendations'], $dropped['recommendations']] = $this->moveRelations($dup, $kept, $snapshot);
            [$moved['page views'], $dropped['page views']] = $this->moveViews($dup, $kept, $snapshot);
            [$moved['collection entries'], $dropped['collection entries']] = $this->moveUnique($dup, $kept, $snapshot, 'collection_items', 'collection_id');
            [$moved['library holdings'], $dropped['library holdings']] = $this->moveHoldings($dup, $kept, $snapshot);

            foreach (self::POINTERS as $table => $label) {
                $snapshot['pointers'][$table] = DB::table($table)->where('book_id', $dup->id)->orderBy('id')->pluck('id')->all();
                DB::table($table)->where('book_id', $dup->id)->update(['book_id' => $kept->id]);
                $moved[$label] = count($snapshot['pointers'][$table]);
            }

            $filled = $this->fillBlanks($dup, $kept, $snapshot);

            // Books merged into the duplicate earlier now live on the kept one.
            $snapshot['chained'] = BookMerge::where('book_id', $dup->id)->pluck('id')->all();
            BookMerge::whereIn('id', $snapshot['chained'])->update(['book_id' => $kept->id]);

            if (DB::table('editions')->where('book_id', $dup->id)->exists()) {
                throw new RuntimeException("Book {$dup->id} still has editions; nothing was merged.");
            }

            $firstAuthor = collect($snapshot['authors'])->sortBy('position')->first();
            $authorName = $firstAuthor ? DB::table('authors')->where('id', $firstAuthor['author_id'])->value('name') : null;

            $merge = BookMerge::create([
                'merged_book_id' => $dup->id,
                'book_id' => $kept->id,
                'merged_title' => $dup->uzbek_title,
                'merge_key' => CatalogueRecords::bookKey($authorName, (string) $dup->uzbek_title),
                'snapshot' => $snapshot,
            ]);

            // Whatever is left (its own author and shelf rows) goes with it.
            DB::table('books')->where('id', $dup->id)->delete();

            return [
                'merge_id' => $merge->id,
                'moved' => array_filter($moved),
                'dropped' => array_filter($dropped),
                'filled' => $filled,
                'retitled' => $retitled,
            ];
        });

        // The kept page, the old address, every listing that counted both.
        if ($revalidate) {
            $this->revalidator->everything();
        }

        return $report;
    }

    /**
     * Puts a merged book back as it was before the merge, from the snapshot
     * the merge left: the book under its old id, its editions, credits,
     * shelves and everything else that moved, and the kept book's blanks
     * emptied again where nobody has changed them since.
     */
    public function unmerge(BookMerge $merge, bool $revalidate = true): void
    {
        DB::transaction(function () use ($merge) {
            $snapshot = $merge->snapshot;
            $dupId = (int) $merge->merged_book_id;
            $keptId = (int) $merge->book_id;
            $now = now();

            if (DB::table('books')->where('id', $dupId)->exists()) {
                throw new RuntimeException("Book {$dupId} exists again; it cannot be restored over.");
            }

            DB::table('books')->insert($snapshot['book']);

            DB::table('editions')->whereIn('id', $snapshot['editions'])->update(['book_id' => $dupId, 'updated_at' => $now]);

            foreach ($snapshot['authors'] as $row) {
                DB::table('book_author')->insertOrIgnore(['book_id' => $dupId, ...$row]);
            }
            DB::table('book_author')->where('book_id', $keptId)->whereIn('author_id', $snapshot['authors_added'])->delete();

            foreach ($snapshot['categories'] as $row) {
                DB::table('book_category')->insertOrIgnore(['book_id' => $dupId, ...$row]);
            }
            DB::table('book_category')->where('book_id', $keptId)->whereIn('category_id', $snapshot['categories_added'])->delete();
            if ($snapshot['primary_from_merge'] !== null) {
                DB::table('book_category')->where('book_id', $keptId)->where('category_id', $snapshot['primary_from_merge'])->update(['is_primary' => false]);
            }

            DB::table('book_relations')->whereIn('id', $snapshot['relations']['moved_out'])->update(['book_id' => $dupId]);
            DB::table('book_relations')->whereIn('id', $snapshot['relations']['moved_in'])->update(['related_book_id' => $dupId]);
            DB::table('book_relations')->insertOrIgnore($snapshot['relations']['dropped']);

            DB::table('book_views')->whereIn('id', $snapshot['views']['moved'])->update(['book_id' => $dupId]);
            foreach ($snapshot['views']['added'] as ['into' => $intoRow, 'row' => $row]) {
                DB::table('book_views')->where('id', $intoRow)->decrement('views', min($row['views'], (int) DB::table('book_views')->where('id', $intoRow)->value('views')));
                DB::table('book_views')->insertOrIgnore($row);
            }

            DB::table('collection_items')->whereIn('id', $snapshot['collection_items']['moved'])->update(['book_id' => $dupId]);
            DB::table('collection_items')->insertOrIgnore($snapshot['collection_items']['dropped']);

            DB::table('library_holdings')->whereIn('id', $snapshot['library_holdings']['moved'])->update(['book_id' => $dupId]);
            foreach ($snapshot['library_holdings']['added'] as ['into' => $intoRow, 'row' => $row]) {
                $current = DB::table('library_holdings')->where('id', $intoRow)->first();
                if ($current !== null) {
                    DB::table('library_holdings')->where('id', $intoRow)->update([
                        'copies_total' => max(0, $current->copies_total - $row['copies_total']),
                        'copies_available' => max(0, $current->copies_available - $row['copies_available']),
                    ]);
                }
                DB::table('library_holdings')->insertOrIgnore($row);
            }

            foreach ($snapshot['pointers'] as $table => $ids) {
                DB::table($table)->whereIn('id', $ids)->update(['book_id' => $dupId]);
            }

            $kept = DB::table('books')->where('id', $keptId)->first();
            foreach ($snapshot['filled'] as $field => ['was' => $was, 'now' => $value]) {
                if ($kept !== null && (string) $kept->{$field} === (string) $value) {
                    DB::table('books')->where('id', $keptId)->update([$field => $was]);
                }
            }

            BookMerge::whereIn('id', $snapshot['chained'])->update(['book_id' => $dupId]);

            // Their authors or genre may have changed back: the bot hears of it.
            DB::table('editions')->where('book_id', $keptId)->update(['updated_at' => $now]);

            $merge->delete();
        });

        if ($revalidate) {
            $this->revalidator->everything();
        }
    }

    /** "Rus tili", "Ruscha", "rus" → "rus". */
    private static function languageStem(string $label): string
    {
        $stem = UzbekText::key(preg_replace('/\s+tili$/iu', '', trim($label)));

        return preg_replace('/cha$/', '', $stem);
    }

    /**
     * The kept book's credits stand. Only a kept book with no authors at all
     * (an empty record) takes the duplicate's, leaving out an "author" that
     * is only the book's title typed into the wrong field. Returns whether
     * the kept book's authors changed.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function moveAuthors(object $dup, object $kept, array &$snapshot): bool
    {
        $snapshot['authors'] = DB::table('book_author')->where('book_id', $dup->id)->orderBy('position')->orderBy('id')
            ->get(['author_id', 'position'])->map(fn ($row) => (array) $row)->all();
        $snapshot['authors_added'] = [];

        if (DB::table('book_author')->where('book_id', $kept->id)->exists() || $snapshot['authors'] === []) {
            return false;
        }

        $titles = [UzbekText::key((string) $dup->uzbek_title), UzbekText::key((string) $kept->uzbek_title)];
        $names = DB::table('authors')->whereIn('id', array_column($snapshot['authors'], 'author_id'))->pluck('name', 'id');

        foreach ($snapshot['authors'] as $row) {
            if (in_array(UzbekText::key((string) ($names[$row['author_id']] ?? '')), $titles, true)) {
                continue;
            }

            DB::table('book_author')->insert(['book_id' => $kept->id, ...$row]);
            $snapshot['authors_added'][] = $row['author_id'];
        }

        return $snapshot['authors_added'] !== [];
    }

    /**
     * Every shelf of either book; the kept book's genre, or the duplicate's
     * when the kept one has none. Returns whether the kept book's genre
     * changed.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function moveShelves(object $dup, object $kept, array &$snapshot): bool
    {
        $snapshot['categories'] = DB::table('book_category')->where('book_id', $dup->id)->orderBy('id')
            ->get(['category_id', 'is_primary'])->map(fn ($row) => ['category_id' => $row->category_id, 'is_primary' => (bool) $row->is_primary])->all();
        $snapshot['categories_added'] = [];
        $snapshot['primary_from_merge'] = null;

        $keptShelves = DB::table('book_category')->where('book_id', $kept->id)->pluck('category_id')->map(intval(...))->all();
        $keptHasGenre = DB::table('book_category')->where('book_id', $kept->id)->where('is_primary', true)->exists();

        foreach ($snapshot['categories'] as $row) {
            $takesGenre = ! $keptHasGenre && $row['is_primary'];

            if (! in_array((int) $row['category_id'], $keptShelves, true)) {
                DB::table('book_category')->insert(['book_id' => $kept->id, 'category_id' => $row['category_id'], 'is_primary' => $takesGenre]);
                $snapshot['categories_added'][] = $row['category_id'];
            } elseif ($takesGenre) {
                DB::table('book_category')->where('book_id', $kept->id)->where('category_id', $row['category_id'])->update(['is_primary' => true]);
            }

            if ($takesGenre) {
                $snapshot['primary_from_merge'] = $row['category_id'];
                $keptHasGenre = true;
            }
        }

        return $snapshot['primary_from_merge'] !== null;
    }

    /**
     * Recommendations from and to the duplicate become the kept book's,
     * except one pointing at itself or one the kept book already has.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{int, int}
     */
    private function moveRelations(object $dup, object $kept, array &$snapshot): array
    {
        $snapshot['relations'] = ['moved_out' => [], 'moved_in' => [], 'dropped' => []];

        foreach (DB::table('book_relations')->where('book_id', $dup->id)->orderBy('id')->get() as $row) {
            $target = (int) $row->related_book_id === (int) $dup->id ? (int) $kept->id : (int) $row->related_book_id;
            $clash = $target === (int) $kept->id
                || DB::table('book_relations')->where(['book_id' => $kept->id, 'related_book_id' => $target, 'source' => $row->source])->exists();

            if ($clash) {
                $snapshot['relations']['dropped'][] = (array) $row;
                DB::table('book_relations')->where('id', $row->id)->delete();
            } else {
                $snapshot['relations']['moved_out'][] = $row->id;
                DB::table('book_relations')->where('id', $row->id)->update(['book_id' => $kept->id]);
            }
        }

        foreach (DB::table('book_relations')->where('related_book_id', $dup->id)->orderBy('id')->get() as $row) {
            $clash = (int) $row->book_id === (int) $kept->id
                || DB::table('book_relations')->where(['book_id' => $row->book_id, 'related_book_id' => $kept->id, 'source' => $row->source])->exists();

            if ($clash) {
                $snapshot['relations']['dropped'][] = (array) $row;
                DB::table('book_relations')->where('id', $row->id)->delete();
            } else {
                $snapshot['relations']['moved_in'][] = $row->id;
                DB::table('book_relations')->where('id', $row->id)->update(['related_book_id' => $kept->id]);
            }
        }

        $moved = count($snapshot['relations']['moved_out']) + count($snapshot['relations']['moved_in']);

        return [$moved, count($snapshot['relations']['dropped'])];
    }

    /**
     * Page views are a counter per day and language: a day both books were
     * opened adds the duplicate's count to the kept book's.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{int, int}
     */
    private function moveViews(object $dup, object $kept, array &$snapshot): array
    {
        $snapshot['views'] = ['moved' => [], 'added' => []];

        foreach (DB::table('book_views')->where('book_id', $dup->id)->orderBy('id')->get() as $row) {
            $own = DB::table('book_views')->where(['book_id' => $kept->id, 'day' => $row->day, 'locale' => $row->locale])->value('id');

            if ($own !== null) {
                DB::table('book_views')->where('id', $own)->increment('views', (int) $row->views);
                DB::table('book_views')->where('id', $row->id)->delete();
                $snapshot['views']['added'][] = ['into' => $own, 'row' => (array) $row];
            } else {
                DB::table('book_views')->where('id', $row->id)->update(['book_id' => $kept->id]);
                $snapshot['views']['moved'][] = $row->id;
            }
        }

        return [count($snapshot['views']['moved']), count($snapshot['views']['added'])];
    }

    /**
     * A table where a book appears once per parent (a collection): the
     * duplicate's entry moves unless the kept book is already there.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{int, int}
     */
    private function moveUnique(object $dup, object $kept, array &$snapshot, string $table, string $parent): array
    {
        $snapshot[$table] = ['moved' => [], 'dropped' => []];
        $already = DB::table($table)->where('book_id', $kept->id)->pluck($parent)->map(intval(...))->all();

        foreach (DB::table($table)->where('book_id', $dup->id)->orderBy('id')->get() as $row) {
            if (in_array((int) $row->{$parent}, $already, true)) {
                $snapshot[$table]['dropped'][] = (array) $row;
                DB::table($table)->where('id', $row->id)->delete();
            } else {
                $snapshot[$table]['moved'][] = $row->id;
                DB::table($table)->where('id', $row->id)->update(['book_id' => $kept->id]);
            }
        }

        return [count($snapshot[$table]['moved']), count($snapshot[$table]['dropped'])];
    }

    /**
     * A library that listed both books has the copies of both: they are
     * added to the kept book's holding.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{int, int}
     */
    private function moveHoldings(object $dup, object $kept, array &$snapshot): array
    {
        $snapshot['library_holdings'] = ['moved' => [], 'added' => []];

        foreach (DB::table('library_holdings')->where('book_id', $dup->id)->orderBy('id')->get() as $row) {
            $own = DB::table('library_holdings')->where(['book_id' => $kept->id, 'library_id' => $row->library_id])->first();

            if ($own !== null) {
                DB::table('library_holdings')->where('id', $own->id)->update([
                    'copies_total' => $own->copies_total + $row->copies_total,
                    'copies_available' => $own->copies_available + $row->copies_available,
                ]);
                DB::table('library_holdings')->where('id', $row->id)->delete();
                $snapshot['library_holdings']['added'][] = ['into' => $own->id, 'row' => (array) $row];
            } else {
                DB::table('library_holdings')->where('id', $row->id)->update(['book_id' => $kept->id]);
                $snapshot['library_holdings']['moved'][] = $row->id;
            }
        }

        return [count($snapshot['library_holdings']['moved']), count($snapshot['library_holdings']['added'])];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string> the fields filled
     */
    private function fillBlanks(object $dup, object $kept, array &$snapshot): array
    {
        $fill = [];

        foreach (self::FILLABLE as $field) {
            if (blank($kept->{$field}) && filled($dup->{$field})) {
                $fill[$field] = $dup->{$field};
            }
        }

        // A series and the place in it travel together.
        if ($kept->series_id === null && $dup->series_id !== null) {
            $fill['series_id'] = $dup->series_id;
            $fill['series_position'] = $dup->series_position;
        }

        $snapshot['filled'] = collect($fill)->map(fn ($value, $field) => ['was' => $kept->{$field}, 'now' => $value])->all();

        if ($fill !== []) {
            DB::table('books')->where('id', $kept->id)->update([...$fill, 'updated_at' => now()]);
        }

        return array_keys($fill);
    }
}
