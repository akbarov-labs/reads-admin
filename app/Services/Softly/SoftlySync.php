<?php

namespace App\Services\Softly;

use App\Models\Library;
use App\Models\LibraryHolding;
use App\Models\LibraryImportRow;
use App\Models\SoftlyBook;
use App\Services\LibraryBookMatcher;
use App\Services\SiteRevalidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings the libraries that run on Softly up to date, in two steps.
 *
 * 1. Read Softly's book list into softly_books. A quick run reads from the
 *    newest change back to the last one it saw, usually one page; a full
 *    run reads every page and takes away books Softly no longer lists. A
 *    new or renamed book is matched to the catalogue on title and author,
 *    and only an exact match is taken (LibraryBookMatcher, rule 2): Softly
 *    has no ISBN, and a wrong "bor" is worse than none.
 *
 * 2. Work out each linked library's holdings from softly_books: copies at
 *    its location, and how many are not out. This is local and runs every
 *    time, so linking a library needs no request. A linked library's list
 *    belongs to Softly: books it does not list there are taken off.
 *
 * Softly's API was opened to us on the condition that we keep the load
 * low; every request goes through SoftlyClient, which enforces that.
 */
class SoftlySync
{
    /** A run that reads more pages than the list has, plus this, has gone wrong. */
    private const SPARE_PAGES = 3;

    public function __construct(
        private readonly SoftlyClient $client,
        private readonly LibraryBookMatcher $matcher,
        private readonly SiteRevalidator $revalidator,
    ) {}

    /**
     * @return array<string, mixed> what the run did, for the log and the command
     */
    public function run(bool $full = false): array
    {
        $lock = Cache::lock('softly:sync', 1800);

        if (! $lock->get()) {
            return ['skipped' => 'another sync is running'];
        }

        try {
            $report = $this->read($full || config('services.softly.quick_mode') === 'full');
            $report['holdings'] = $this->writeHoldings($report['complete']);
        } finally {
            $lock->release();
        }

        Log::info('Softly sync', $report);

        return $report;
    }

    /** @return array<string, mixed> */
    private function read(bool $full): array
    {
        $existing = SoftlyBook::all()->keyBy('softly_id');
        // Nothing stored yet means there is nothing to stop at: read it all.
        $cursor = $full ? null : $existing->max('softly_updated_at');
        $full = $cursor === null;

        $report = [
            'mode' => $full ? 'full' : 'changed',
            'requests' => 0,
            'added' => 0,
            'changed' => 0,
            'removed' => 0,
            'matched' => 0,
            // Copies that went out or came back while their book's
            // updatedAt stayed put: a quick run would not have seen them.
            // Counted on full runs; if it is not 0, set SOFTLY_QUICK_MODE=full.
            'missed_by_quick' => 0,
            'complete' => false,
            'locations' => [],
            'error' => null,
        ];

        $seen = [];
        $page = 1;

        try {
            do {
                $result = $this->client->books($page);
                $report['requests']++;
                $reachedCursor = false;

                foreach ($result['items'] as $item) {
                    if (! isset($item['id'], $item['name'])) {
                        continue;
                    }

                    $updatedAt = isset($item['updatedAt']) ? CarbonImmutable::parse($item['updatedAt']) : null;

                    // Second precision on both sides: the column drops the
                    // milliseconds Softly sends.
                    if ($cursor !== null && $updatedAt !== null && $updatedAt->startOfSecond()->lt($cursor)) {
                        $reachedCursor = true;

                        continue;
                    }

                    $seen[(int) $item['id']] = true;
                    $this->store($item, $updatedAt, $existing->get((int) $item['id']), $full, $report);
                }

                $lastPage = count($result['items']) < config('services.softly.page_size')
                    || $page * config('services.softly.page_size') >= $result['total'];
                $page++;

                if ($page > (int) ceil($result['total'] / max(1, config('services.softly.page_size'))) + self::SPARE_PAGES) {
                    throw new SoftlyUnavailable('More pages than the list has; stopping.');
                }
            } while (! $reachedCursor && ! $lastPage);

            $report['complete'] = true;
        } catch (SoftlyUnavailable $e) {
            $report['error'] = $e->getMessage();
        }

        // Only a full run that reached the end knows what is gone.
        if ($full && $report['complete']) {
            $report['removed'] += SoftlyBook::whereNotIn('softly_id', array_keys($seen))->delete();
        }

        if ($full) {
            $report['locations'] = $this->locations();
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $report
     */
    private function store(array $item, ?CarbonImmutable $updatedAt, ?SoftlyBook $row, bool $full, array &$report): void
    {
        if (! empty($item['deletedAt'])) {
            if ($row !== null) {
                $row->delete();
                $report['removed']++;
            }

            return;
        }

        $name = trim((string) $item['name']);
        $author = isset($item['author']['name']) ? trim((string) $item['author']['name']) : null;
        $matchedOn = mb_substr($name."\n".($author ?? ''), 0, 1001);
        $stocks = $this->stocks($item['stocks'] ?? []);

        $values = [
            'name' => mb_substr($name, 0, 500),
            'author' => $author === null || $author === '' ? null : mb_substr($author, 0, 500),
            'stocks' => $stocks,
            'softly_updated_at' => $updatedAt,
        ];

        // A new title, a renamed one, or (on a full run) one that matched
        // nothing before and may now that the catalogue has grown.
        if ($row === null || $row->matched_on !== $matchedOn || ($full && $row->book_id === null)) {
            $match = $this->matcher->match($values['name'], $values['author'], null);
            $exact = $match['match'] === LibraryImportRow::MATCH_EXACT;

            $values += [
                'book_id' => $exact ? $match['book_id'] : null,
                'match' => $match['match'],
                'candidate_ids' => $exact ? null : ($match['candidate_ids'] ?: null),
                'matched_on' => $matchedOn,
            ];

            if ($exact && $row?->book_id !== $match['book_id']) {
                $report['matched']++;
            }
        }

        if ($row === null) {
            SoftlyBook::create(['softly_id' => (int) $item['id'], ...$values]);
            $report['added']++;

            return;
        }

        if ($full && $row->stocks != $stocks && $updatedAt !== null
            && $row->softly_updated_at?->equalTo($updatedAt->startOfSecond())) {
            $report['missed_by_quick']++;
        }

        $row->fill($values);

        if ($row->isDirty()) {
            $row->save();
            $report['changed']++;
        }
    }

    /**
     * @param  mixed  $stocks  Softly's copies: [{id, busy, locationId}]
     * @return list<array{id: int, busy: bool, locationId: int}>
     */
    private function stocks(mixed $stocks): array
    {
        return collect(is_array($stocks) ? $stocks : [])
            ->filter(fn ($stock) => is_array($stock) && isset($stock['locationId']))
            ->map(fn (array $stock) => [
                'id' => (int) ($stock['id'] ?? 0),
                'busy' => (bool) ($stock['busy'] ?? false),
                'locationId' => (int) $stock['locationId'],
            ])
            ->sortBy('id')
            ->values()
            ->all();
    }

    /**
     * Copies per Softly location, and which library each is linked to, so
     * the log says which ids there are to link.
     *
     * @return array<int, array{copies: int, library: ?string}>
     */
    private function locations(): array
    {
        $copies = [];

        foreach (SoftlyBook::query()->pluck('stocks') as $stocks) {
            foreach ($stocks as $stock) {
                $copies[$stock['locationId']] = ($copies[$stock['locationId']] ?? 0) + 1;
            }
        }

        ksort($copies);
        $linked = Library::query()->whereNotNull('softly_location_id')->pluck('slug', 'softly_location_id');

        return collect($copies)
            ->map(fn (int $count, int $location) => ['copies' => $count, 'library' => $linked[$location] ?? null])
            ->all();
    }

    /**
     * Each linked library's holdings, from softly_books. One site ping per
     * library whose list changed, none for the others. After a read that
     * reached the end, every linked list counts as checked now, changed or
     * not: the site shows readers when it was.
     *
     * @return array<string, array{added: int, updated: int, removed: int}>
     */
    private function writeHoldings(bool $checked): array
    {
        $libraries = Library::query()->whereNotNull('softly_location_id')->get();

        if ($libraries->isEmpty()) {
            return [];
        }

        // location => book_id => [copies, on the shelf]
        $counts = [];

        foreach (SoftlyBook::query()->whereNotNull('book_id')->get(['book_id', 'stocks']) as $book) {
            foreach ($book->stocks as $stock) {
                $here = &$counts[$stock['locationId']][$book->book_id];
                $here ??= [0, 0];
                $here[0]++;
                $here[1] += $stock['busy'] ? 0 : 1;
                unset($here);
            }
        }

        $report = [];

        foreach ($libraries as $library) {
            $wanted = $counts[$library->softly_location_id] ?? [];
            $done = ['added' => 0, 'updated' => 0, 'removed' => 0];

            DB::transaction(function () use ($library, $wanted, &$done) {
                LibraryHolding::withoutEvents(function () use ($library, $wanted, &$done) {
                    $existing = $library->holdings()->get()->keyBy('book_id');

                    foreach ($wanted as $bookId => [$total, $available]) {
                        $holding = $existing->get($bookId);

                        if ($holding === null) {
                            $library->holdings()->create([
                                'book_id' => $bookId,
                                'copies_total' => $total,
                                'copies_available' => $available,
                            ]);
                            $done['added']++;

                            continue;
                        }

                        $holding->fill(['copies_total' => $total, 'copies_available' => $available]);

                        if ($holding->isDirty()) {
                            $holding->save();
                            $done['updated']++;
                        }
                    }

                    $done['removed'] = $library->holdings()->whereNotIn('book_id', array_keys($wanted))->delete();
                });
            });

            if (array_sum($done) > 0) {
                $library->touchHoldings();
                $this->revalidator->library($library->slug);
            } elseif ($checked) {
                $library->touchHoldings();
            }

            $report[$library->slug] = $done;
        }

        return $report;
    }
}
