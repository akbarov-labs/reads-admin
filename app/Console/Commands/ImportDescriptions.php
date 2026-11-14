<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\SiteRevalidator;
use App\Support\UzbekText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('books:import-descriptions
    {file : CSV with the columns book_id and description}
    {--dry-run : Count what would be filled, change nothing}
    {--over-copies : Also give a book its own text when only its editions carry one (a shop or channel copy)}')]
#[Description('Give books with no description of their own the reviewed description from a file')]
class ImportDescriptions extends Command
{
    /**
     * Only a book with no description at all is filled: none of its own and
     * none on any edition, because an edition's text already stands in for
     * the book on its page. The text becomes the book's own description,
     * and also the description of each of its editions that has none, so
     * the bot, which keeps descriptions per ISBN, gets it on its next pull
     * (filling clears the edition's sync_hash).
     *
     * With --over-copies, a book whose only text sits on its editions — the
     * Asaxiy import's or a publisher channel's words — gets the reviewed
     * text as its own description too. The editions keep theirs: the page
     * leads with the book's own text and folds the shop's under it.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column, "\u{FEFF} ")), fgetcsv($handle) ?: []);

        if (! in_array('book_id', $header, true) || ! in_array('description', $header, true)) {
            $this->error('The file needs the columns book_id and description.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $overCopies = (bool) $this->option('over-copies');
        $counts = ['filled' => 0, 'over a copy' => 0, 'editions too' => 0, 'already described' => 0, 'no such book' => 0, 'empty text' => 0];
        $log = ['book_id,edition_ids'];

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_combine($header, array_pad($row, count($header), null));
            $text = UzbekText::cleanParagraphs($row['description'] ?? null);
            $book = Book::with('editions')->find((int) $row['book_id']);

            if ($book === null) {
                $counts['no such book']++;
                $this->line("No book {$row['book_id']}.");

                continue;
            }

            if ($text === null) {
                $counts['empty text']++;

                continue;
            }

            $editionsHaveText = $book->editions->contains(fn ($edition) => filled(trim((string) $edition->description)));

            if (filled($book->description) || ($editionsHaveText && ! $overCopies)) {
                $counts['already described']++;
                $this->line("Book {$book->id} “{$book->uzbek_title}” already has a description; left alone.");

                continue;
            }

            $editions = $book->editions->filter(fn ($edition) => blank(trim((string) $edition->description)));

            if (! $dryRun) {
                DB::transaction(function () use ($book, $editions, $text) {
                    $book->forceFill(['description' => $text])->save();

                    foreach ($editions as $edition) {
                        $edition->forceFill(['description' => $text, 'sync_hash' => null])->save();
                    }
                });
            }

            $counts['filled']++;
            $counts['over a copy'] += $editionsHaveText ? 1 : 0;
            $counts['editions too'] += $editions->count();
            $log[] = $book->id.',"'.$editions->pluck('id')->implode(' ').'"';
        }

        fclose($handle);

        $this->table(['', 'Books'], collect($counts)->map(fn ($count, $what) => [$what, $count])->values()->all());

        if (! $dryRun && $counts['filled'] > 0) {
            // To undo: clear description on these books and editions.
            $logPath = 'description-imports/'.now()->format('Y-m-d_His').'.csv';
            Storage::disk('local')->put($logPath, implode("\n", $log)."\n");
            $this->line('Logged to '.Storage::disk('local')->path($logPath));

            app(SiteRevalidator::class)->everything();
        }

        $this->info(sprintf('%s %d books.', $dryRun ? 'Would describe' : 'Described', $counts['filled']));

        return self::SUCCESS;
    }
}
