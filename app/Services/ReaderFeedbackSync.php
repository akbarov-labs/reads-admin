<?php

namespace App\Services;

use App\Enums\ModerationStatus;
use App\Models\Edition;
use App\Models\ReaderReview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What Kitoblarim bot readers think of a book (docs/DATA-MODEL.md, "Ratings
 * and reviews from the bot"). The bot sends each list whole whenever it
 * changes, so anything left out of a list is gone in the bot too.
 */
class ReaderFeedbackSync
{
    /** More changed books than this and the whole site is refreshed instead. */
    private const PING_LIMIT = 50;

    public function __construct(private readonly SiteRevalidator $revalidator) {}

    /**
     * Rating totals per ISBN, and how the stars split from one to five when
     * the bot sends that (`stars`, ones first). An edition the list leaves
     * out goes back to no ratings.
     *
     * @param  list<array{isbn: string, count: int, total: int, stars?: list<int>}>  $items
     * @return array{updated: int, unknown: int}
     */
    public function ratings(array $items): array
    {
        $wanted = [];

        foreach ($items as $item) {
            if ($isbn = CatalogueSync::normalizeIsbn((string) $item['isbn'])) {
                $wanted[$isbn] = [
                    'reader_rating_count' => (int) $item['count'],
                    'reader_rating_total' => (int) $item['total'],
                    'reader_rating_stars' => isset($item['stars']) ? array_map(intval(...), array_values($item['stars'])) : null,
                ];
            }
        }

        return $this->writeTotals($wanted, [
            'reader_rating_count' => 0,
            'reader_rating_total' => 0,
            'reader_rating_stars' => null,
        ]);
    }

    /**
     * How many of the bot's readers want to read each ISBN, are reading it
     * and have read it. An edition the list leaves out goes back to none.
     *
     * @param  list<array{isbn: string, want: int, reading: int, read: int}>  $items
     * @return array{updated: int, unknown: int}
     */
    public function readers(array $items): array
    {
        $wanted = [];

        foreach ($items as $item) {
            if ($isbn = CatalogueSync::normalizeIsbn((string) $item['isbn'])) {
                $wanted[$isbn] = [
                    'reader_want_count' => (int) $item['want'],
                    'reader_reading_count' => (int) $item['reading'],
                    'reader_read_count' => (int) $item['read'],
                ];
            }
        }

        return $this->writeTotals($wanted, [
            'reader_want_count' => 0,
            'reader_reading_count' => 0,
            'reader_read_count' => 0,
        ]);
    }

    /**
     * Writes per-ISBN totals onto editions: the values sent, or `$empty` for
     * an edition that has some and was left out. The columns are written
     * without touching updated_at: a count is not an edit of the edition,
     * and the bot's feed orders by it.
     *
     * @param  array<string, array<string, mixed>>  $wanted  isbn => column => value
     * @param  array<string, mixed>  $empty  column => the value for "no readers"
     * @return array{updated: int, unknown: int}
     */
    private function writeTotals(array $wanted, array $empty): array
    {
        $found = [];
        $changedBooks = [];
        $updated = 0;
        $columns = array_keys($empty);

        $apply = function (Collection $editions) use ($wanted, $empty, $columns, &$found, &$changedBooks, &$updated) {
            foreach ($editions as $edition) {
                $found[$edition->isbn] = true;
                $values = $wanted[$edition->isbn] ?? $empty;

                if (collect($columns)->every(fn (string $column) => $edition->getAttribute($column) == $values[$column])) {
                    continue;
                }

                Edition::whereKey($edition->id)->toBase()->update(
                    array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $values),
                );
                $updated++;

                if ($edition->status === ModerationStatus::Approved) {
                    $changedBooks[$edition->book_id] = true;
                }
            }
        };

        $select = ['id', 'book_id', 'isbn', 'status', ...$columns];

        DB::transaction(function () use ($wanted, $empty, $apply, $select) {
            foreach (array_chunk(array_keys($wanted), 500) as $chunk) {
                $apply(Edition::query()->whereIn('isbn', $chunk)->get($select));
            }

            // Editions with something the bot no longer sends.
            $holding = Edition::query()->where(function ($query) use ($empty) {
                foreach ($empty as $column => $none) {
                    $none === null ? $query->orWhereNotNull($column) : $query->orWhere($column, '>', 0);
                }
            });
            $apply($holding->get($select)->reject(fn ($edition) => isset($wanted[$edition->isbn])));
        });

        $this->ping(array_keys($changedBooks));

        return [
            'updated' => $updated,
            'unknown' => count(array_diff_key($wanted, $found)),
        ];
    }

    /**
     * Every review the bot's readers made public. A new one waits for a
     * moderator; one whose text or rating changed goes back to waiting; one
     * the list leaves out is deleted.
     *
     * @param  list<array{id: string, isbn: string, rating: ?int, text: string, authorName: ?string, writtenAt: string}>  $items
     * @return array{created: int, updated: int, removed: int, unknown: int}
     */
    public function reviews(array $items): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'removed' => 0, 'unknown' => 0];
        $changedBooks = [];

        $isbns = collect($items)->map(fn ($item) => CatalogueSync::normalizeIsbn((string) $item['isbn']))->filter()->unique()->values();
        $editions = $isbns->chunk(500)->flatMap(fn ($chunk) => Edition::query()
            ->whereIn('isbn', $chunk->all())
            ->where('status', ModerationStatus::Approved)
            ->get(['id', 'book_id', 'isbn']))
            ->keyBy('isbn');

        // A plain closure, not fn (): the arrow form would capture $summary by value.
        Model::withoutEvents(function () use ($items, $editions, &$summary, &$changedBooks) {
            DB::transaction(function () use ($items, $editions, &$summary, &$changedBooks) {
                $existing = ReaderReview::all()->keyBy('external_id');
                $kept = [];

                foreach ($items as $item) {
                    $edition = $editions->get(CatalogueSync::normalizeIsbn((string) $item['isbn']));

                    if ($edition === null) {
                        $summary['unknown']++;

                        continue;
                    }

                    $id = (string) $item['id'];
                    $kept[$id] = true;
                    $values = [
                        'book_id' => $edition->book_id,
                        'edition_id' => $edition->id,
                        'author_name' => filled($item['authorName'] ?? null) ? trim((string) $item['authorName']) : null,
                        'rating' => isset($item['rating']) ? (int) $item['rating'] : null,
                        'body' => trim((string) $item['text']),
                        'written_at' => CarbonImmutable::parse($item['writtenAt']),
                    ];

                    $review = $existing->get($id);

                    if ($review === null) {
                        ReaderReview::create(['external_id' => $id, ...$values]);
                        $summary['created']++;

                        continue;
                    }

                    $review->fill($values);

                    // What a moderator approved is the text and stars; new ones
                    // need a new look. A date or a name moving does not.
                    if ($review->isDirty(['body', 'rating', 'book_id']) && $review->status !== ModerationStatus::Pending) {
                        if ($review->status === ModerationStatus::Approved) {
                            $changedBooks[$review->getOriginal('book_id')] = true;
                        }
                        $review->status = ModerationStatus::Pending;
                    }

                    if ($review->isDirty()) {
                        if ($review->status === ModerationStatus::Approved) {
                            $changedBooks[$review->book_id] = true;
                        }
                        $review->save();
                        $summary['updated']++;
                    }
                }

                foreach ($existing as $id => $review) {
                    if (isset($kept[$id])) {
                        continue;
                    }

                    if ($review->status === ModerationStatus::Approved) {
                        $changedBooks[$review->book_id] = true;
                    }
                    $review->delete();
                    $summary['removed']++;
                }
            });
        });

        $this->ping(array_keys($changedBooks));

        return $summary;
    }

    /** @param  list<int|string>  $bookIds */
    private function ping(array $bookIds): void
    {
        if (count($bookIds) > self::PING_LIMIT) {
            $this->revalidator->everything();

            return;
        }

        foreach ($bookIds as $id) {
            $this->revalidator->book($id);
        }
    }
}
