<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Reviewed genres from a file, for books whose shelves left the choice to a person. */
class ImportGenresTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_only_a_genre_among_the_books_own_shelves_and_only_where_none_is_set(): void
    {
        Storage::fake('local');
        Http::fake();

        $business = Category::create(['slug' => 'biznes', 'name' => 'Biznes']);
        $psychology = Category::create(['slug' => 'psixologiya-va-shaxsiy-rivojlanish', 'name' => 'Psixologiya va shaxsiy rivojlanish']);
        $history = Category::create(['slug' => 'tarix', 'name' => 'Tarix']);
        $top100 = Category::create(['slug' => 'top-100-bestseller', 'name' => 'TOP-100 bestseller']);

        $mixed = $this->bookOn('Aralash', [$business, $psychology, $top100]);
        $wrongPick = $this->bookOn('Boshqa javon', [$business, $psychology]);
        $listPick = $this->bookOn("Ro'yxat", [$business, $psychology, $top100]);
        $alreadySet = $this->bookOn('Belgilangan', [$business, $psychology]);
        $alreadySet->setGenre($business);

        $file = tempnam(sys_get_temp_dir(), 'genres');
        file_put_contents($file, implode("\n", [
            'book_id,category_id',
            "{$mixed->id},{$psychology->id}",
            "{$wrongPick->id},{$history->id}",
            "{$listPick->id},{$top100->id}",
            "{$alreadySet->id},{$psychology->id}",
            '999999,1',
        ])."\n");

        $this->assertSame(0, Artisan::call('books:import-genres', ['file' => $file, '--dry-run' => true]));
        $this->assertStringContainsString('Would set a genre for 1 books.', Artisan::output());
        $this->assertNull($mixed->fresh()->genre());

        $this->assertSame(0, Artisan::call('books:import-genres', ['file' => $file]));
        $this->assertStringContainsString('Set a genre for 1 books.', Artisan::output());

        $this->assertSame($psychology->id, $mixed->fresh()->genre()?->id);
        $this->assertNull($wrongPick->fresh()->genre());
        $this->assertNull($listPick->fresh()->genre());
        $this->assertSame($business->id, $alreadySet->fresh()->genre()?->id);

        $logs = Storage::disk('local')->files('genre-assignments');
        $this->assertCount(1, $logs);
        $this->assertSame(
            "book_id,category_id,category\n{$mixed->id},{$psychology->id},\"Psixologiya va shaxsiy rivojlanish\"\n",
            Storage::disk('local')->get($logs[0]),
        );
    }

    /** @param  list<Category>  $shelves */
    private function bookOn(string $title, array $shelves): Book
    {
        $book = Book::create(['uzbek_title' => $title]);

        foreach ($shelves as $shelf) {
            $book->categories()->attach($shelf->id);
        }

        return $book;
    }
}
