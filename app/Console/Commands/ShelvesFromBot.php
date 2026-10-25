<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Edition;
use App\Services\CatalogueSync;
use App\Services\SiteRevalidator;
use App\Support\BotGenres;
use App\Support\UzbekText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one-time catch-up for books the bot sent before it sent genres: puts
 * each book that is on no shelf on the shelf its bot genre names. Reads
 * "isbn<TAB>genre" lines, as psql prints them from the bot's database, and
 * changes nothing without --apply. Books already on a shelf are left alone.
 */
#[Signature('books:shelves-from-bot
    {file? : Lines of isbn<TAB>genre; standard input when left out}
    {--apply : Write the shelves. Without it this only reports what it would do}')]
#[Description('Put books that are on no shelf on the shelf of their Kitoblarim bot genre. Dry run unless --apply.')]
class ShelvesFromBot extends Command
{
    private const EXAMPLES = 10;

    public function handle(SiteRevalidator $revalidator): int
    {
        $genres = $this->readGenres();
        $shelves = new BotGenres;

        $editions = collect(array_chunk(array_keys($genres), 500))
            ->flatMap(fn (array $isbns) => Edition::whereIn('isbn', $isbns)->get(['id', 'book_id', 'isbn']));
        $shelved = DB::table('book_category')->whereIn('book_id', $editions->pluck('book_id')->unique())->pluck('book_id')->flip();

        $votes = [];
        $unmapped = [];
        $alreadyShelved = [];

        foreach ($editions as $edition) {
            if ($shelved->has($edition->book_id)) {
                $alreadyShelved[$edition->book_id] = true;

                continue;
            }

            $genre = $genres[$edition->isbn];
            $shelf = $shelves->shelf($genre);

            if ($shelf === null) {
                $unmapped[$genre] = ($unmapped[$genre] ?? 0) + 1;

                continue;
            }

            $votes[$edition->book_id][$shelf->id] = ($votes[$edition->book_id][$shelf->id] ?? 0) + 1;
        }

        // Editions of one book can disagree: the genre most of them name
        // wins, and on a tie the older shelf.
        $fill = collect($votes)->map(function (array $counts) {
            uksort($counts, fn (int $a, int $b) => [$counts[$b], $a] <=> [$counts[$a], $b]);

            return array_key_first($counts);
        })->sortKeys();

        $this->report($fill, $unmapped);

        $this->line(count($genres).' ISBNs with a genre read. '
            .(count($genres) - $editions->pluck('isbn')->unique()->count()).' are not in Reads, '
            .count($alreadyShelved).' books are already on a shelf (left alone), '
            .$fill->count().' books to put on a shelf.');

        if (! $this->option('apply')) {
            $this->line('Dry run: nothing was written. Run again with --apply to write these shelves.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($fill) {
            foreach ($fill as $bookId => $categoryId) {
                // Checked again here: a book an admin shelved since the
                // report keeps what they chose.
                if (! DB::table('book_category')->where('book_id', $bookId)->exists()) {
                    DB::table('book_category')->insert(['book_id' => $bookId, 'category_id' => $categoryId, 'is_primary' => true]);
                }
            }
        });

        $revalidator->everything();
        $this->info("Put {$fill->count()} books on a shelf.");

        return self::SUCCESS;
    }

    /**
     * ISBN-13 => genre. The bot keeps an older book's ISBN-10 as typed;
     * Reads has the same book under its ISBN-13. Lines with no ISBN or no
     * genre are dropped.
     *
     * @return array<string, string>
     */
    private function readGenres(): array
    {
        $path = $this->argument('file') ?? 'php://stdin';
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot read {$path}.");
        }

        $genres = [];

        while (($line = fgets($handle)) !== false) {
            [$isbn, $genre] = array_pad(explode("\t", rtrim($line, "\r\n"), 2), 2, null);
            $isbn = self::isbn13((string) $isbn);
            $genre = UzbekText::clean($genre);

            if ($isbn !== null && $genre !== null) {
                $genres[$isbn] = $genre;
            }
        }

        fclose($handle);

        return $genres;
    }

    private static function isbn13(string $raw): ?string
    {
        $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw));

        if (preg_match('/^\d{9}[\dX]$/', $digits) === 1) {
            $digits = '978'.substr($digits, 0, 9);
            $sum = 0;

            foreach (str_split($digits) as $i => $digit) {
                $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
            }

            $digits .= (10 - $sum % 10) % 10;
        }

        return CatalogueSync::normalizeIsbn($digits);
    }

    /**
     * @param  Collection<int, int>  $fill  book ID => category ID
     * @param  array<string, int>  $unmapped  genre => editions
     */
    private function report(Collection $fill, array $unmapped): void
    {
        $names = Category::whereIn('id', $fill->unique())->pluck('name', 'id');

        $this->table(
            ['Shelf', 'Books', 'Examples (book IDs)'],
            $fill->groupBy(fn (int $categoryId) => $categoryId, preserveKeys: true)
                ->sortByDesc(fn ($books) => $books->count())
                ->map(fn ($books, int $categoryId) => [$names[$categoryId], $books->count(), $books->keys()->take(self::EXAMPLES)->implode(', ')])
                ->values()->all(),
        );

        if ($unmapped !== []) {
            arsort($unmapped);
            $this->line('Genres with no shelf, on books that have none (left alone):');
            $this->table(['Bot genre', 'Editions'], collect($unmapped)->map(fn (int $count, string $genre) => [$genre, $count])->values()->all());
        }
    }
}
