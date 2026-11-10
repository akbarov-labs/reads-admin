<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\SiteRevalidator;
use App\Support\Genres;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('books:import-genres
    {file : CSV with the columns book_id and category_id}
    {--dry-run : Count what would be set, change nothing}')]
#[Description('Give books with shelves but no genre the reviewed genre from a file, chosen among their own shelves')]
class ImportGenres extends Command
{
    /**
     * The books:assign-genres pass leaves a book alone when its shelves
     * don't make one genre plain ("Biznes" + "Psixologiya"). This sets the
     * genre a person chose for such a book, and only when it is still
     * without one and the chosen genre is one of its own shelves and not a
     * list. Book::setGenre does the rest, the bot's next pull included.
     */
    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path) || ($handle = fopen($path, 'r')) === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column, "\u{FEFF} ")), fgetcsv($handle) ?: []);

        if (! in_array('book_id', $header, true) || ! in_array('category_id', $header, true)) {
            $this->error('The file needs the columns book_id and category_id.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = ['set' => 0, 'already has a genre' => 0, 'not one of its shelves' => 0, 'no such book' => 0];
        $byGenre = [];
        $log = ['book_id,category_id,category'];

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_combine($header, array_pad($row, count($header), null));
            $book = Book::with('categories')->find((int) $row['book_id']);

            if ($book === null) {
                $counts['no such book']++;

                continue;
            }

            if ($book->genre() !== null) {
                $counts['already has a genre']++;

                continue;
            }

            $genre = $book->categories->firstWhere('id', (int) $row['category_id']);

            if ($genre === null || Genres::isList($genre)) {
                $counts['not one of its shelves']++;
                $this->line("Book {$book->id}: category {$row['category_id']} is not one of its genre shelves; left alone.");

                continue;
            }

            if (! $dryRun) {
                $book->setGenre($genre);
            }

            $counts['set']++;
            $byGenre[$genre->name] = ($byGenre[$genre->name] ?? 0) + 1;
            $log[] = $book->id.','.$genre->id.',"'.str_replace('"', '""', $genre->name).'"';
        }

        fclose($handle);

        arsort($byGenre);
        $this->table(['Genre', 'Books'], collect($byGenre)->map(fn ($count, $name) => [$name, $count])->values()->all());
        $this->table(['', 'Books'], collect($counts)->map(fn ($count, $what) => [$what, $count])->values()->all());

        if (! $dryRun && $counts['set'] > 0) {
            // To undo: set is_primary = 0 for these book/category pairs.
            $logPath = 'genre-assignments/reviewed-'.now()->format('Y-m-d_His').'.csv';
            Storage::disk('local')->put($logPath, implode("\n", $log)."\n");
            $this->line('Logged to '.Storage::disk('local')->path($logPath));

            app(SiteRevalidator::class)->everything();
        }

        $this->info(sprintf('%s a genre for %d books.', $dryRun ? 'Would set' : 'Set', $counts['set']));

        return self::SUCCESS;
    }
}
