<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\SoftlyBook;
use App\Services\SiteRevalidator;
use App\Services\Softly\SoftlyNewBooks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

#[Signature('softly:apply-decisions
    {file : CSV with the columns action, book_id, softly_title, softly_author}
    {--dry-run : Only say what would be done}')]
#[Description('Settle many "Softly matches" titles at once from a spreadsheet: link, new or skip')]
class ApplySoftlyDecisions extends Command
{
    /**
     * The spreadsheet route for the "To check" list on the Softly matches
     * page, for when there are hundreds: one row per title, as the review
     * export writes it, with an action an admin filled in.
     *
     *  - link: the title is the book in book_id;
     *  - new:  make it a book under its library title and author;
     *  - skip: not a book to add.
     *
     * Any other action (check, blank) leaves the title as it is. Each
     * decision is for the title in every library, as on the page. A title
     * settled since the export is left alone.
     */
    public function handle(SoftlyNewBooks $newBooks, SiteRevalidator $revalidator): int
    {
        $path = (string) $this->argument('file');
        $handle = is_readable($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $header = array_map(fn (string $column) => trim(str_replace("\u{FEFF}", '', $column)), fgetcsv($handle) ?: []);

        foreach (['action', 'book_id', 'softly_title', 'softly_author'] as $column) {
            if (! in_array($column, $header, true)) {
                $this->error("The file has no {$column} column.");

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('dry-run');
        $done = ['link' => 0, 'new' => 0, 'skip' => 0, 'left' => 0, 'already settled' => 0];
        $problems = [];

        Model::withoutEvents(function () use ($handle, $header, $dry, $newBooks, &$done, &$problems) {
            $line = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $line++;

                if (count($values) < count($header)) {
                    continue;
                }

                $cell = array_combine($header, array_slice($values, 0, count($header)));
                $action = strtolower(trim($cell['action']));

                if (! in_array($action, ['link', 'new', 'skip'], true)) {
                    $done['left']++;

                    continue;
                }

                $row = SoftlyBook::query()
                    ->whereNull('book_id')
                    ->where('name', $cell['softly_title'])
                    ->where('author', $cell['softly_author'] === '' ? null : $cell['softly_author'])
                    ->orderBy('id')
                    ->first();

                if ($row === null) {
                    $done['already settled']++;

                    continue;
                }

                if ($action === 'link') {
                    $book = ctype_digit(trim($cell['book_id'])) ? Book::find((int) $cell['book_id']) : null;

                    if ($book === null) {
                        $problems[] = "Line {$line}: no book {$cell['book_id']} for \"{$cell['softly_title']}\".";

                        continue;
                    }

                    if (! $dry) {
                        $newBooks->link($row, $book->id);
                    }
                } elseif (! $dry && $action === 'new') {
                    $newBooks->create($row, $row->name, $row->author);
                } elseif (! $dry && $action === 'skip') {
                    $newBooks->skip($row);
                }

                $done[$action]++;
            }
        });

        fclose($handle);

        if (! $dry && $done['new'] > 0) {
            $revalidator->everything();
        }

        $this->line(sprintf(
            '%s %d linked, %d made new books, %d skipped. %d left for the page, %d already settled.',
            $dry ? 'Would be:' : 'Done:',
            $done['link'], $done['new'], $done['skip'], $done['left'], $done['already settled'],
        ));

        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        if (! $dry) {
            $this->line('Library lists on the site change at the next sync.');
        }

        return self::SUCCESS;
    }
}
