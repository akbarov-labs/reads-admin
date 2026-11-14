<?php

namespace App\Console\Commands;

use App\Enums\ModerationStatus;
use App\Models\Book;
use App\Models\BookMerge;
use App\Services\BookMerger;
use App\Services\SiteRevalidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Merges books that are one work entered twice, from a reviewed CSV of
 * `keep_id,merge_id` rows (a header, extra columns such as a note, blank
 * lines and #-comments are allowed). See BookMerger for what moves.
 *
 * The whole file is checked before anything is written: an id merged twice,
 * merged and kept, or kept as itself stops the run. A row whose book is
 * gone, or whose kept book is not on the site while the merged one is, is
 * skipped and reported; the others go ahead.
 *
 * --dry-run runs each merge for real inside a transaction and rolls it
 * back, so the counts are the ones a real run would produce, and nothing
 * is pinged. Every run is logged to storage/app/private/book-merges/: a CSV
 * to read and, for a real run, a JSON file with each merge's snapshot. To
 * undo a merge: `php artisan books:unmerge <merged id>`.
 */
#[Signature('books:merge
    {file : CSV of keep_id,merge_id rows}
    {--dry-run : Run the merges and roll them back: report only, change nothing}
    {--force : Do not ask for confirmation}')]
#[Description('Merge books that are one work entered twice (keep_id,merge_id CSV). Use --dry-run first.')]
class MergeBooks extends Command
{
    public function handle(BookMerger $merger, SiteRevalidator $revalidator): int
    {
        $pairs = $this->readPairs((string) $this->argument('file'));

        if ($pairs === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Merge '.count($pairs).' books? (books:unmerge undoes one)')) {
            return self::FAILURE;
        }

        $rows = [];

        foreach ($pairs as [$keepId, $mergeId]) {
            if (! $dryRun) {
                $rows[] = $this->mergeOne($merger, $keepId, $mergeId, $dryRun);

                continue;
            }

            // One row at a time, rolled back straight away: the database
            // (SQLite on the server) is never locked for longer than one
            // merge, so the bot's sync is not kept waiting.
            DB::beginTransaction();

            try {
                $rows[] = $this->mergeOne($merger, $keepId, $mergeId, $dryRun);
            } finally {
                DB::rollBack();
            }
        }

        $merged = collect($rows)->whereIn('outcome', ['merged', 'would merge'])->count();

        if (! $dryRun && $merged > 0) {
            // One ping for all of it: book pages, listings and counts.
            $revalidator->everything();
        }

        $this->log($rows, $dryRun);

        $skipped = count($rows) - $merged;
        $this->info(($dryRun ? "Dry run: {$merged} books would be merged" : "Merged {$merged} books")
            .($skipped > 0 ? ", {$skipped} skipped." : '.')
            .($dryRun ? ' Nothing was written.' : ''));

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function mergeOne(BookMerger $merger, int $keepId, int $mergeId, bool $dryRun): array
    {
        $kept = Book::find($keepId);
        $duplicate = Book::find($mergeId);
        $row = [
            'keep_id' => $keepId,
            'keep_title' => $kept?->uzbek_title,
            'merge_id' => $mergeId,
            'merge_title' => $duplicate?->uzbek_title,
            'outcome' => 'skipped',
            'details' => '',
            'warnings' => [],
            'merge_record' => null,
            'snapshot' => null,
        ];

        $reason = match (true) {
            $kept === null && BookMerge::where('merged_book_id', $keepId)->exists() => "kept book {$keepId} was itself merged into ".BookMerge::keptIdFor($keepId),
            $kept === null => "kept book {$keepId} does not exist",
            $duplicate === null && BookMerge::where('merged_book_id', $mergeId)->exists() => 'already merged into '.BookMerge::keptIdFor($mergeId),
            $duplicate === null => "book {$mergeId} does not exist",
            $kept->status !== ModerationStatus::Approved && $duplicate->status === ModerationStatus::Approved => "the kept book is {$kept->status->value} and the merged one is on the site: keep {$mergeId} instead, or approve {$keepId} first",
            default => null,
        };

        if ($reason !== null) {
            $row['details'] = $reason;
            $this->warn("{$mergeId} → {$keepId}: skipped, {$reason}");

            return $row;
        }

        $row['warnings'] = $merger->warnings($duplicate, $kept);

        try {
            $report = $merger->merge($duplicate, $kept, revalidate: false);
        } catch (Throwable $e) {
            $row['details'] = 'failed: '.$e->getMessage();
            $this->error("{$mergeId} → {$keepId}: {$row['details']}");

            return $row;
        }

        $row['outcome'] = $dryRun ? 'would merge' : 'merged';
        $row['details'] = $this->describe($report);

        if (! $dryRun) {
            $row['merge_record'] = $report['merge_id'];
            $row['snapshot'] = BookMerge::find($report['merge_id'])?->snapshot;
        }

        $this->line("{$mergeId} «{$row['merge_title']}» → {$keepId} «{$row['keep_title']}»: {$row['details']}");

        foreach ($row['warnings'] as $warning) {
            $this->warn("    ! {$warning}");
        }

        return $row;
    }

    /** @param  array{moved: array<string, int>, dropped: array<string, int>, filled: list<string>, retitled: int}  $report */
    private function describe(array $report): string
    {
        $parts = [];

        foreach ($report['moved'] as $what => $count) {
            $parts[] = "{$count} {$what}";
        }

        $text = $parts === [] ? 'nothing to move' : 'moved '.implode(', ', $parts);

        if ($report['dropped'] !== []) {
            $text .= '; already on the kept book (folded in): '.collect($report['dropped'])->map(fn ($count, $what) => "{$count} {$what}")->implode(', ');
        }

        if ($report['filled'] !== []) {
            $text .= '; filled '.implode(', ', $report['filled']);
        }

        if ($report['retitled'] > 0) {
            $text .= "; {$report['retitled']} editions now show the kept title";
        }

        return $text;
    }

    /**
     * The pairs, or null after reporting why the file cannot be used.
     *
     * @return list<array{int, int}>|null
     */
    private function readPairs(string $path): ?array
    {
        $handle = is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            $this->error("Cannot read {$path}.");

            return null;
        }

        $pairs = [];
        $errors = [];
        $line = 0;

        while (($fields = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            $first = trim((string) ($fields[0] ?? ''));

            if ($first === '' || str_starts_with($first, '#') || ($line === 1 && ! ctype_digit($first))) {
                continue;
            }

            $second = trim((string) ($fields[1] ?? ''));

            if (! ctype_digit($first) || ! ctype_digit($second)) {
                $errors[] = "line {$line}: expected keep_id,merge_id, got \"{$first},{$second}\"";

                continue;
            }

            $pairs[] = [(int) $first, (int) $second];
        }

        fclose($handle);

        $merged = array_count_values(array_column($pairs, 1));
        $kept = array_flip(array_column($pairs, 0));

        foreach ($pairs as [$keepId, $mergeId]) {
            if ($keepId === $mergeId) {
                $errors[] = "{$mergeId} is to be merged into itself";
            }
            if ($merged[$mergeId] > 1) {
                $errors[] = "{$mergeId} is to be merged {$merged[$mergeId]} times";
            }
            if (isset($kept[$mergeId])) {
                $errors[] = "{$mergeId} is both merged and kept: merge every copy into one book";
            }
        }

        if ($errors !== []) {
            $this->error('Nothing was merged. Fix the file first:');
            foreach (array_unique($errors) as $error) {
                $this->line("  {$error}");
            }

            return null;
        }

        if ($pairs === []) {
            $this->error("No keep_id,merge_id rows in {$path}.");

            return null;
        }

        return $pairs;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function log(array $rows, bool $dryRun): void
    {
        $stamp = now()->format('Y-m-d_His').($dryRun ? '-dry-run' : '');
        $disk = Storage::disk('local');

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['keep_id', 'keep_title', 'merge_id', 'merge_title', 'outcome', 'details', 'warnings', 'merge_record'], escape: '');
        foreach ($rows as $row) {
            fputcsv($csv, [
                $row['keep_id'], $row['keep_title'], $row['merge_id'], $row['merge_title'],
                $row['outcome'], $row['details'], implode(' | ', $row['warnings']), $row['merge_record'],
            ], escape: '');
        }
        rewind($csv);
        $disk->put("book-merges/{$stamp}.csv", stream_get_contents($csv));
        fclose($csv);
        $this->line('Logged to '.$disk->path("book-merges/{$stamp}.csv"));

        if (! $dryRun) {
            // The snapshots live on the book_merges rows too; this copy
            // outlives a kept book that is deleted later.
            $disk->put("book-merges/{$stamp}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}
