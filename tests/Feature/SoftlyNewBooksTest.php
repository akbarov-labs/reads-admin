<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\SoftlyBookResource;
use App\Filament\Resources\SoftlyBookResource\Pages\ListSoftlyBooks;
use App\Models\Author;
use App\Models\Book;
use App\Models\LibraryImportRow;
use App\Models\SoftlyBook;
use App\Models\User;
use App\Services\LibraryBookMatcher;
use App\Services\Softly\SoftlyNewBooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Softly titles the catalogue lacks: made books, or settled by an admin. */
class SoftlyNewBooksTest extends TestCase
{
    use RefreshDatabase;

    private Book $chayon;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $qodiriy = Author::create(['name' => 'Abdulla Qodiriy', 'slug' => 'abdulla-qodiriy', 'original_name' => 'Абдулла Қодирий']);
        $this->chayon = Book::create(['uzbek_title' => 'Mehrobdan chayon']);
        $this->chayon->authors()->attach($qodiriy);
        $this->chayon->editions()->create([]);
    }

    /** A Softly row as the sync stores it, matched the way the sync matches it. */
    private function row(int $library, int $id, string $name, ?string $author, int $copies = 1): SoftlyBook
    {
        $match = app(LibraryBookMatcher::class)->match($name, $author, null);
        $exact = $match['match'] === LibraryImportRow::MATCH_EXACT;

        return SoftlyBook::create([
            'softly_library' => $library,
            'softly_id' => $id,
            'name' => $name,
            'author' => $author,
            'stocks' => array_map(fn (int $n) => ['id' => $id * 100 + $n, 'busy' => false, 'locationId' => $library], range(1, $copies)),
            'book_id' => $exact ? $match['book_id'] : null,
            'match' => $match['match'],
            'candidate_ids' => $exact ? null : ($match['candidate_ids'] ?: null),
            'matched_on' => $name."\n".$author,
        ]);
    }

    private function newBooks(): SoftlyNewBooks
    {
        return app(SoftlyNewBooks::class);
    }

    public function test_a_title_with_nothing_like_it_becomes_one_book_for_every_library(): void
    {
        $mehr = $this->row(1, 10, 'Kecha va kunduz', "Cho'lpon");
        // The same book at Kitobqand, spelled with another apostrophe.
        $kitobqand = $this->row(16, 20, 'Kecha va kunduz', 'Cho‘lpon', copies: 2);
        $this->assertSame(LibraryImportRow::MATCH_NONE, $mehr->match);

        $done = $this->newBooks()->createWaiting();

        $this->assertSame(['created' => 1, 'found' => 0], $done);
        $book = Book::where('uzbek_title', 'Kecha va kunduz')->sole();
        $this->assertSame(["Cho'lpon"], $book->authors->pluck('name')->all());
        $this->assertCount(1, $book->editions);
        $this->assertNull($book->editions->first()->isbn);

        foreach ([$mehr, $kitobqand] as $row) {
            $this->assertSame($book->id, $row->fresh()->book_id);
            $this->assertSame(SoftlyBook::MATCH_CREATED, $row->fresh()->match);
        }

        // Next time the sync meets the title, it is an exact match.
        $this->assertSame(LibraryImportRow::MATCH_EXACT, app(LibraryBookMatcher::class)->match('Kecha va kunduz', "Cho'lpon", null)['match']);
    }

    public function test_the_author_is_found_under_another_alphabet(): void
    {
        $this->row(1, 10, "Bo'ronlar", 'Абдулла Қодирий');

        $this->newBooks()->createWaiting();

        $this->assertSame(['Abdulla Qodiriy'], Book::where('uzbek_title', "Bo'ronlar")->sole()->authors->pluck('name')->all());
        $this->assertSame(1, Author::count());
    }

    public function test_near_matches_and_skipped_titles_are_not_made_books(): void
    {
        $near = $this->row(1, 10, 'Mehrobdan', null);
        $skipped = $this->row(1, 11, 'Daftar 48 varaq', null);
        $this->newBooks()->skip($skipped);

        $this->assertSame(LibraryImportRow::MATCH_SUGGESTED, $near->match);
        $this->assertSame(['created' => 0, 'found' => 0], $this->newBooks()->createWaiting());
        $this->assertSame(1, Book::count());
    }

    public function test_the_dry_run_counts_books_and_new_authors(): void
    {
        $this->row(1, 10, 'Kecha va kunduz', "Cho'lpon");
        $this->row(16, 20, 'Kecha va kunduz', "Cho'lpon");
        $this->row(1, 11, 'Kumush', "Cho'lpon");
        $this->row(1, 12, "Bo'ronlar", 'Абдулла Қодирий');

        $preview = $this->newBooks()->preview();

        $this->assertSame(3, $preview['books']);
        $this->assertSame(4, $preview['rows']);
        $this->assertSame(["Cho'lpon" => 2], $preview['new_authors']);
        $this->assertSame(0, Book::where('uzbek_title', 'Kumush')->count());

        $this->artisan('softly:add-books --dry-run')->expectsOutputToContain('3 book(s) would be made')->assertSuccessful();
    }

    public function test_linking_a_near_match_links_it_in_every_library(): void
    {
        $mehr = $this->row(1, 10, 'Mehrobdan', 'Abdulla Qodiriy');
        $izlash = $this->row(22, 30, 'Mehrobdan', 'Abdulla Qodiriy');

        $this->newBooks()->link($mehr, $this->chayon->id);

        foreach ([$mehr, $izlash] as $row) {
            $this->assertSame($this->chayon->id, $row->fresh()->book_id);
            $this->assertSame(LibraryImportRow::MATCH_MANUAL, $row->fresh()->match);
        }
    }

    public function test_a_book_made_from_softly_can_be_taken_back_and_is_not_made_again(): void
    {
        $row = $this->row(1, 10, 'Tafakkur jurnali 3-son', null);
        $this->newBooks()->createWaiting();
        $book = $row->fresh()->book;

        $this->assertTrue($this->newBooks()->remove($row->fresh()));

        $this->assertNull(Book::find($book->id));
        $this->assertTrue($row->fresh()->skipped);
        $this->assertSame(0, $this->newBooks()->createWaiting()['created']);
    }

    public function test_a_book_edited_since_it_was_made_is_not_taken_back(): void
    {
        $row = $this->row(1, 10, 'Kecha va kunduz', "Cho'lpon");
        $this->newBooks()->createWaiting();
        $row->fresh()->book->editions()->first()->update(['isbn' => '9789943000017']);

        $this->assertFalse($this->newBooks()->remove($row->fresh()));
        $this->assertNotNull($row->fresh()->book_id);
    }

    public function test_the_review_page_settles_a_title_for_admins_only(): void
    {
        $near = $this->row(1, 10, 'Mehrobdan', 'Abdulla Qodiriy');
        $this->row(16, 20, 'Mehrobdan', 'Abdulla Qodiriy');
        // The title, by someone else: a near match, not the same book.
        $other = $this->row(1, 11, 'Mehrobdan chayon', 'Hamid Olimjon');

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        Livewire::test(ListSoftlyBooks::class)
            // One row per title, not one per library.
            ->assertCanSeeTableRecords([$near, $other])
            ->assertCountTableRecords(2)
            ->callTableAction('link', $near, data: ['candidate' => $this->chayon->id])
            ->assertHasNoTableActionErrors()
            ->callTableAction('create', $other, data: ['title' => 'Mehrobdan chayon', 'author' => 'Hamid Olimjon'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, SoftlyBook::whereNull('book_id')->count());
        $made = Book::where('uzbek_title', 'Mehrobdan chayon')->whereKeyNot($this->chayon->id)->sole();
        $this->assertSame(['Hamid Olimjon'], $made->authors->pluck('name')->all());

        $this->actingAs(User::create(['name' => 'Mehr', 'email' => 'mehr@example.test', 'password' => 'password', 'role' => UserRole::Library]));
        $this->get(SoftlyBookResource::getUrl())->assertForbidden();
    }
}
