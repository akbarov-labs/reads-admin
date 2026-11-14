<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\SiteRevalidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('books:clear-copied-texts
    {file : CSV with the column book_id: books whose copied text describes another book}
    {--dry-run : Show what would be cleared, change nothing}')]
#[Description('Take a copied shop or channel text that describes another book off the books in a file')]
class ClearCopiedTexts extends Command
{
    /**
     * The text a book page shows when the book has none of its own comes
     * from an edition (Book::aboutEdition). When a review found that text is
     * about a different book, it is cleared from every approved edition of
     * the listed book that carries it, so it is neither the description nor
     * the folded shop text. The old text is logged with its edition, to put
     * back if a listing was wrong. The bot's copy is left as it is.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column, "\u{FEFF} ")), fgetcsv($handle) ?: []);

        if (! in_array('book_id', $header, true)) {
            $this->error('The file needs the column book_id.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['cleared' => 0, 'editions' => 0, 'no copied text' => 0, 'no such book' => 0];
        $log = [];

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_combine($header, array_pad($row, count($header), null));
            $book = Book::with('approvedEditions')->find((int) $row['book_id']);

            if ($book === null) {
                $counts['no such book']++;
                $this->line("No book {$row['book_id']}.");

                continue;
            }

            $text = trim((string) $book->aboutEdition()?->description);

            if ($text === '') {
                $counts['no copied text']++;

                continue;
            }

            $editions = $book->approvedEditions->filter(fn ($edition) => trim((string) $edition->description) === $text);

            foreach ($editions as $edition) {
                $log[] = ['book_id' => $book->id, 'edition_id' => $edition->id, 'description' => $edition->description];
            }

            if (! $dryRun) {
                DB::transaction(function () use ($editions) {
                    foreach ($editions as $edition) {
                        $edition->forceFill(['description' => null])->save();
                    }
                });
            }

            $counts['cleared']++;
            $counts['editions'] += $editions->count();
            $this->line(sprintf('Book %d “%s”: %d edition(s).', $book->id, $book->uzbek_title, $editions->count()));
        }

        fclose($handle);

        $this->table(['', 'Count'], collect($counts)->map(fn ($count, $what) => [$what, $count])->values()->all());

        if (! $dryRun && $counts['cleared'] > 0) {
            // To undo: write each logged description back to its edition.
            $logPath = 'description-clears/'.now()->format('Y-m-d_His').'.json';
            Storage::disk('local')->put($logPath, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('Logged to '.Storage::disk('local')->path($logPath));

            app(SiteRevalidator::class)->everything();
        }

        $this->info(sprintf('%s the copied text on %d books.', $dryRun ? 'Would clear' : 'Cleared', $counts['cleared']));

        return self::SUCCESS;
    }
}
