<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\BookResource\Pages\EditBook;
use App\Models\Book;
use App\Models\Category;
use App\Models\Edition;
use App\Models\User;
use App\Support\Genres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * A book's genre is its primary shelf: how it is chosen, set by hand, and
 * filled in for books whose shelves already make it plain.
 */
class GenreTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private Category $fiction;

    private Category $world;

    private Category $uzbek;

    private Category $business;

    private Category $psychology;

    private Category $top100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fiction = Category::create(['slug' => 'badiiy-adabiyot', 'name' => 'Badiiy adabiyot']);
        $this->world = Category::create(['slug' => 'jahon-adabiyoti', 'name' => 'Jahon adabiyoti', 'parent_id' => $this->fiction->id]);
        $this->uzbek = Category::create(['slug' => 'ozbek-adabiyoti', 'name' => "O'zbek adabiyoti", 'parent_id' => $this->fiction->id]);
        $this->business = Category::create(['slug' => 'biznes', 'name' => 'Biznes']);
        $this->psychology = Category::create(['slug' => 'psixologiya-va-shaxsiy-rivojlanish', 'name' => 'Psixologiya va shaxsiy rivojlanish']);
        $this->top100 = Category::create(['slug' => 'top-100-bestseller', 'name' => 'TOP-100 bestseller']);
    }

    // ------------------------------------------------------------------
    // Which genre shelves make plain
    // ------------------------------------------------------------------

    public function test_a_single_shelf_is_the_genre(): void
    {
        $this->assertSame($this->business->id, Genres::evident([$this->business])?->id);
    }

    public function test_a_shelf_gives_way_to_its_sub_shelf(): void
    {
        $this->assertSame($this->world->id, Genres::evident([$this->fiction, $this->world])?->id);
    }

    public function test_lists_are_never_the_genre(): void
    {
        $this->assertSame($this->world->id, Genres::evident([$this->fiction, $this->world, $this->top100])?->id);
        $this->assertNull(Genres::evident([$this->top100]));
        $this->assertNotContains($this->top100->id, Genres::choices()->pluck('id'));
    }

    public function test_two_unrelated_shelves_or_two_sub_shelves_are_left_to_a_person(): void
    {
        $this->assertNull(Genres::evident([$this->business, $this->psychology]));
        $this->assertNull(Genres::evident([$this->fiction, $this->world, $this->uzbek]));
        $this->assertNull(Genres::evident([]));
    }

    // ------------------------------------------------------------------
    // Setting it
    // ------------------------------------------------------------------

    public function test_set_genre_puts_the_book_on_the_shelf_as_its_only_primary_and_moves_its_editions_up_the_feed(): void
    {
        $book = $this->catalogueBook(edition: ['isbn' => '9789943231986']);
        $book->categories()->attach($this->business->id, ['is_primary' => true]);
        $book->categories()->attach($this->top100->id);
        DB::table('editions')->update(['updated_at' => '2026-01-01 00:00:00']);

        Carbon::setTestNow('2026-11-09 12:00:00');
        $this->assertTrue($book->setGenre($this->world));

        $this->assertSame($this->world->id, $book->genre()?->id);
        $this->assertSame([[$this->world->id, 1], [$this->business->id, 0], [$this->top100->id, 0]], $this->shelvesOf($book));
        $this->assertSame('2026-11-09 12:00:00', Edition::sole()->updated_at->format('Y-m-d H:i:s'));

        // The same genre again is no change and leaves the feed alone.
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->assertFalse($book->setGenre($this->world));
        $this->assertSame('2026-11-09 12:00:00', Edition::sole()->updated_at->format('Y-m-d H:i:s'));

        // No genre keeps the shelves.
        $this->assertTrue($book->setGenre(null));
        $this->assertNull($book->genre());
        $this->assertCount(3, $this->shelvesOf($book));
    }

    public function test_an_admin_sets_the_genre_in_the_book_form(): void
    {
        $book = $this->catalogueBook();
        $book->categories()->attach($this->fiction->id);

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        Livewire::test(EditBook::class, ['record' => $book->getRouteKey()])
            ->assertFormSet(['genre_id' => null])
            ->fillForm(['categories' => [$this->fiction->id], 'genre_id' => $this->uzbek->id])
            ->call('save')
            ->assertHasNoFormErrors();

        // The genre is put on its shelf even though it was not picked as one.
        $this->assertSame([[$this->fiction->id, 0], [$this->uzbek->id, 1]], $this->shelvesOf($book));

        Livewire::test(EditBook::class, ['record' => $book->getRouteKey()])
            ->assertFormSet(['genre_id' => $this->uzbek->id]);
    }

    // ------------------------------------------------------------------
    // books:assign-genres
    // ------------------------------------------------------------------

    public function test_the_command_fills_plain_genres_logs_them_and_leaves_the_rest(): void
    {
        Storage::fake('local');

        $single = $this->bookOn([$this->business], 'Bir javon');
        $nested = $this->bookOn([$this->fiction, $this->world, $this->top100], 'Ichma-ich');
        $mixed = $this->bookOn([$this->business, $this->psychology], 'Aralash');
        $alreadySet = $this->bookOn([$this->fiction, $this->uzbek], 'Belgilangan');
        $alreadySet->setGenre($this->fiction);
        $bare = $this->bookOn([], 'Javonsiz');

        $this->assertSame(0, Artisan::call('books:assign-genres', ['--dry-run' => true]));
        $this->assertStringContainsString('Would give a genre to 2 of 3 books; 1 left for a person to choose.', Artisan::output());
        $this->assertNull($single->genre());

        $this->assertSame(0, Artisan::call('books:assign-genres'));
        $output = Artisan::output();

        $this->assertStringContainsString('Gave a genre to 2 of 3 books; 1 left for a person to choose.', $output);
        $this->assertStringContainsString('Biznes + Psixologiya va shaxsiy rivojlanish', $output);
        $this->assertSame($this->business->id, $single->fresh()->genre()?->id);
        $this->assertSame($this->world->id, $nested->fresh()->genre()?->id);
        $this->assertNull($mixed->fresh()->genre());
        $this->assertSame($this->fiction->id, $alreadySet->fresh()->genre()?->id);
        $this->assertSame([], $this->shelvesOf($bare));

        $logs = Storage::disk('local')->files('genre-assignments');
        $this->assertCount(1, $logs);
        $this->assertSame(
            "book_id,category_id,category\n{$single->id},{$this->business->id},\"Biznes\"\n{$nested->id},{$this->world->id},\"Jahon adabiyoti\"\n",
            Storage::disk('local')->get($logs[0]),
        );

        // Run again: nothing left to do.
        Artisan::call('books:assign-genres');
        $this->assertStringContainsString('Gave a genre to 0 of 1 books', Artisan::output());
    }

    // ------------------------------------------------------------------

    /** @param  list<Category>  $shelves */
    private function bookOn(array $shelves, string $title): Book
    {
        $book = Book::create(['uzbek_title' => $title]);

        foreach ($shelves as $shelf) {
            $book->categories()->attach($shelf->id);
        }

        return $book;
    }

    /** @return list<array{0: int, 1: int}> [category ID, is_primary] per shelf */
    private function shelvesOf(Book $book): array
    {
        return DB::table('book_category')->where('book_id', $book->id)->orderBy('category_id')->get()
            ->map(fn ($row) => [(int) $row->category_id, (int) $row->is_primary])->all();
    }
}
