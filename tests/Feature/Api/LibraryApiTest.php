<?php

namespace Tests\Feature\Api;

use App\Enums\ModerationStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\Library;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Libraries on the public API, and which of them hold a book. */
class LibraryApiTest extends TestCase
{
    use RefreshDatabase;

    private Library $mehr;

    private Library $idrok;

    private Book $kunlar;

    private Book $chayon;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-25 12:00:00');

        $this->kunlar = $this->book("O'tkan kunlar");
        $this->kunlar->authors()->attach(Author::create(['name' => 'Abdulla Qodiriy', 'slug' => 'qodiriy']));
        $this->chayon = $this->book('Mehrobdan chayon');

        $this->mehr = Library::create([
            'slug' => 'mehr', 'name' => 'Mehr kutubxonasi', 'region' => 'Toshkent shahri',
            'district' => 'Shayxontohur tumani', 'is_published' => true,
            'hours' => ['uz' => 'Du–Sha 10:00–20:00', 'ru' => 'Пн–Сб 10:00–20:00'],
            'telegram' => '@mehr',
        ]);
        $this->idrok = Library::create([
            'slug' => 'idrok', 'name' => 'Idrok kutubxonasi', 'region' => 'Toshkent viloyati',
            'is_published' => true,
        ]);

        $this->mehr->holdings()->create(['book_id' => $this->kunlar->id, 'copies_total' => 2, 'copies_available' => 0]);
        $this->mehr->holdings()->create(['book_id' => $this->chayon->id, 'copies_total' => 1, 'copies_available' => 1]);
        $this->idrok->holdings()->create(['book_id' => $this->kunlar->id, 'copies_total' => 1, 'copies_available' => 1]);

        // Adding a book stamps the list as confirmed now; these are older.
        Library::whereKey($this->mehr->id)->update(['holdings_updated_at' => '2026-10-24 09:00:00']);
        Library::whereKey($this->idrok->id)->update(['holdings_updated_at' => '2026-07-01 09:00:00']);
    }

    private function book(string $title): Book
    {
        $book = Book::create(['uzbek_title' => $title]);
        $book->editions()->create([]);

        return $book;
    }

    public function test_a_book_lists_the_libraries_that_hold_it_with_free_copies_first(): void
    {
        $libraries = $this->getJson("/api/books/{$this->kunlar->id}")->assertOk()->json('data.libraries');

        $this->assertSame(['idrok', 'mehr'], array_column($libraries, 'slug'));
        $this->assertSame([1, 1, true], [$libraries[0]['copiesTotal'], $libraries[0]['copiesAvailable'], $libraries[0]['stale']]);
        $this->assertSame([2, 0, false], [$libraries[1]['copiesTotal'], $libraries[1]['copiesAvailable'], $libraries[1]['stale']]);
        $this->assertSame('Shayxontohur tumani', $libraries[1]['district']);
    }

    public function test_an_unpublished_library_is_nowhere_on_the_site(): void
    {
        $this->idrok->update(['is_published' => false]);

        $this->assertSame(['mehr'], array_column($this->getJson("/api/books/{$this->kunlar->id}")->json('data.libraries'), 'slug'));
        $this->assertSame(['mehr'], array_column($this->getJson('/api/libraries')->json('data'), 'slug'));
        $this->getJson('/api/libraries/idrok')->assertNotFound();
        $this->getJson('/api/libraries/idrok/books')->assertNotFound();
        $this->assertSame(['mehr'], array_column($this->getJson('/api/sitemap')->json('libraries'), 'slug'));
    }

    public function test_the_list_filters_by_region_and_counts_only_books_on_the_site(): void
    {
        $this->chayon->update(['status' => ModerationStatus::Pending]);

        $response = $this->getJson('/api/libraries?region='.urlencode('Toshkent shahri'))->assertOk();

        $this->assertSame(['mehr'], array_column($response->json('data'), 'slug'));
        $this->assertSame(1, $response->json('data.0.bookCount'));
        $this->assertArrayNotHasKey('hours', $response->json('data.0'));
    }

    public function test_a_librarys_page_has_how_to_reach_it_in_the_asked_language(): void
    {
        $data = $this->getJson('/api/libraries/mehr?locale=ru')->assertOk()->json('data');

        $this->assertSame('Пн–Сб 10:00–20:00', $data['hours']);
        $this->assertSame('https://t.me/mehr', $data['telegramUrl']);
        $this->assertSame(2, $data['bookCount']);
    }

    public function test_a_librarys_books_page_by_page_searchable_and_free_only(): void
    {
        $all = $this->getJson('/api/libraries/mehr/books')->assertOk();
        $this->assertSame(['Mehrobdan chayon', "O'tkan kunlar"], array_column(array_column($all->json('data'), 'book'), 'uzbekTitle'));
        $this->assertSame(2, $all->json('meta.total'));

        $this->assertSame([$this->kunlar->id], array_map(fn ($row) => (int) $row['book']['id'], $this->getJson('/api/libraries/mehr/books?q=qodiriy')->json('data')));
        $this->assertSame([$this->chayon->id], array_map(fn ($row) => (int) $row['book']['id'], $this->getJson('/api/libraries/mehr/books?available=1')->json('data')));
    }
}
