<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * books:shelves-from-bot, the one-time catch-up that puts books the bot
 * sent before it sent genres on their genre's shelf.
 */
class ShelvesFromBotTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private Category $fiction;

    private Category $religion;

    private Category $history;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fiction = Category::create(['slug' => 'badiiy-adabiyot', 'name' => 'Badiiy adabiyot']);
        $this->religion = Category::create(['slug' => 'diniy-adabiyot', 'name' => 'Diniy adabiyot']);
        $this->history = Category::create(['slug' => 'tarix', 'name' => 'Tarix']);
    }

    private function book(string $title, string ...$isbns): Book
    {
        $book = $this->catalogueBook(['uzbek_title' => $title], ['isbn' => array_shift($isbns)]);

        foreach ($isbns as $isbn) {
            $this->edition($book, ['isbn' => $isbn]);
        }

        return $book;
    }

    /** @param  list<string>  $lines */
    private function fill(array $lines, bool $apply = false): string
    {
        $file = tempnam(sys_get_temp_dir(), 'genres');
        file_put_contents($file, implode("\n", $lines)."\n");

        $this->assertSame(0, Artisan::call('books:shelves-from-bot', ['file' => $file, '--apply' => $apply]));
        unlink($file);

        return Artisan::output();
    }

    /** @return array<int, list<array{0: int, 1: int}>> book ID => [category ID, is_primary] */
    private function shelves(): array
    {
        return DB::table('book_category')->orderBy('book_id')->orderBy('category_id')->get()
            ->groupBy('book_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => [(int) $row->category_id, (int) $row->is_primary])->all())
            ->all();
    }

    /** @return list<string> */
    private function lines(): array
    {
        return [
            "9789943000017\tBadiiy adabiyotlar",
            // Two editions of one book disagree: two say religion, one fiction.
            "9789943000024\tDiniy-ma'rifiy adabiyot",
            "9789943000031\tDiniy adabiyotlar",
            "9789943000048\tRoman",
            // Already on a shelf here: left alone whatever the bot says.
            "9789943000055\tTarix",
            // A genre with no shelf.
            "9789943000062\tIlmiy-ommabop",
            // Not in Reads at all.
            "9789943999999\tTarix",
            // An ISBN-10, which Reads has as 978-5-17-080115-2.
            "5-17-080115-X\tTarix va Siyosat",
            // Blank genre, blank line, no tab: skipped.
            "9789943000079\t",
            '',
            '9789943000086',
        ];
    }

    private function catalogue(): array
    {
        $fiction = $this->book('Kecha va kunduz', '9789943000017');
        $religion = $this->book('Hadis', '9789943000024', '9789943000031', '9789943000048');
        $shelved = $this->book('Temur tuzuklari', '9789943000055');
        $shelved->categories()->attach($this->fiction->id, ['is_primary' => false]);
        $science = $this->book('Koinot', '9789943000062');
        $isbn10 = $this->book('Tarix kitobi', '9785170801152');

        return [$fiction, $religion, $shelved, $science, $isbn10];
    }

    public function test_a_dry_run_reports_what_it_would_do_and_writes_nothing(): void
    {
        [$fiction, $religion, $shelved] = $this->catalogue();
        $before = $this->shelves();

        $output = $this->fill($this->lines());

        $this->assertSame($before, $this->shelves());
        $this->assertMatchesRegularExpression("/Badiiy adabiyot\s*\|\s*1\s*\|\s*{$fiction->id}\s*\|/", $output);
        $this->assertMatchesRegularExpression("/Diniy adabiyot\s*\|\s*1\s*\|\s*{$religion->id}\s*\|/", $output);
        $this->assertMatchesRegularExpression('/Ilmiy-ommabop\s*\|\s*1\s*\|/', $output);
        $this->assertStringContainsString('8 ISBNs with a genre read. 1 are not in Reads, 1 books are already on a shelf (left alone), 3 books to put on a shelf.', $output);
        $this->assertStringContainsString('Dry run: nothing was written.', $output);
        $this->assertSame([[$this->fiction->id, 0]], $before[$shelved->id]);
    }

    public function test_apply_shelves_only_books_with_none_and_the_most_common_genre_wins(): void
    {
        [$fiction, $religion, $shelved, $science, $isbn10] = $this->catalogue();

        $output = $this->fill($this->lines(), apply: true);

        $this->assertSame([
            $fiction->id => [[$this->fiction->id, 1]],
            $religion->id => [[$this->religion->id, 1]],
            $shelved->id => [[$this->fiction->id, 0]],
            $isbn10->id => [[$this->history->id, 1]],
        ], $this->shelves());
        $this->assertArrayNotHasKey($science->id, $this->shelves());
        $this->assertStringContainsString('Put 3 books on a shelf.', $output);

        // A second run finds nothing left to do.
        $this->assertStringContainsString('0 books to put on a shelf.', $this->fill($this->lines(), apply: true));
    }

    public function test_on_a_tie_the_older_shelf_wins(): void
    {
        $book = $this->book('Ikki janr', '9789943000017', '9789943000024');

        // The first edition names the newer shelf, so order alone would pick it.
        $this->fill(["9789943000017\tTarix", "9789943000024\tBadiiy adabiyotlar"], apply: true);

        $this->assertSame([$book->id => [[$this->fiction->id, 1]]], $this->shelves());
    }
}
