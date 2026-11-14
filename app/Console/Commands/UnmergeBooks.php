<?php

namespace App\Console\Commands;

use App\Models\BookMerge;
use App\Services\BookMerger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Undoes books:merge for the given old book ids, from the snapshot each
 * merge left on its book_merges row: the book comes back under its old id
 * with its editions, credits, shelves and everything else that moved.
 */
#[Signature('books:unmerge
    {ids* : Ids of books that were merged away (merge_id in the CSV)}
    {--force : Do not ask for confirmation}')]
#[Description('Put back books that books:merge merged into another')]
class UnmergeBooks extends Command
{
    public function handle(BookMerger $merger): int
    {
        $ids = array_map('intval', (array) $this->argument('ids'));

        if (! $this->option('force') && ! $this->confirm('Restore '.count($ids).' merged books?')) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($ids as $id) {
            $merge = BookMerge::where('merged_book_id', $id)->first();

            if ($merge === null) {
                $this->error("{$id}: no merge recorded for this book.");
                $failed = true;

                continue;
            }

            try {
                $merger->unmerge($merge);
                $this->info("{$id}: restored (it had been merged into {$merge->book_id}).");
            } catch (Throwable $e) {
                $this->error("{$id}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
