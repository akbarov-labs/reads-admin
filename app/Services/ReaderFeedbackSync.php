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
     * Rating totals per ISBN. An edition the list leaves out goes back to no
     * ratings. The columns are written without touching updated_at: a rating
     * is not an edit of the edition, and the bot's feed orders by it.
     *
     * @param  list<array{isbn: string, count: int, total: int}>  $items
     * @return array{updated: int, unknown: int}
     */
    public function ratings(array $items): array
    {
        $wanted = [];

        foreach ($items as $item) {
            $isbn = CatalogueSync::normalizeIsbn((string) $item['isbn']);

            if ($isbn !== null) {
                $wanted[$isbn] = [(int) $item['count'], (int) $item['total']];
            }
        }

        $found = [];
        $changedBooks = [];
        $updated = 0;

        $apply = function (Collection $editions) use ($wanted, &$found, &$changedBooks, &$updated) {
            foreach ($editions as $edition) {
                $found[$edition->isbn] = true;
                [$count, $total] = $wanted[$edition->isbn] ?? [0, 0];

                if ($edition->reader_rating_count === $count && $edition->reader_rating_total === $total) {
                    continue;
                }

                Edition::whereKey($edition->id)->toBase()->update([
                    'reader_rating_count' => $count,
                    'reader_rating_total' => $total,
                ]);
                $updated++;

                if ($edition->status === ModerationStatus::Approved) {
                    $changedBooks[$edition->book_id] = true;
                }
            }
        };

        $columns = ['id', 'book_id', 'isbn', 'status', 'reader_rating_count', 'reader_rating_total'];

        DB::transaction(function () use ($wanted, $apply, $columns) {
            foreach (array_chunk(array_keys($wanted), 500) as $chunk) {
                $apply(Edition::query()->whereIn('isbn', $chunk)->get($columns));
            }

            // Ratings that are no longer in the bot.
            $apply(Edition::query()->where('reader_rating_count', '>', 0)->get($columns)
                ->reject(fn ($edition) => isset($wanted[$edition->isbn])));
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
