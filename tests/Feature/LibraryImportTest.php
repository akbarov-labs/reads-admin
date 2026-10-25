<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\LibraryResource;
use App\Filament\Resources\LibraryResource\Pages\ImportHoldings;
use App\Models\Author;
use App\Models\Book;
use App\Models\Library;
use App\Models\LibraryImport;
use App\Models\LibraryImportRow;
use App\Models\User;
use App\Services\LibraryBookMatcher;
use App\Services\LibraryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** A library's Excel list: read, matched to books, checked, applied. */
class LibraryImportTest extends TestCase
{
    use RefreshDatabase;

    private Library $library;

    private Book $kunlar;

    private Book $chayon;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Mehr', 'email' => 'mehr@example.test',
            'password' => 'password', 'role' => UserRole::Library,
        ]);
        $this->library = Library::create([
            'user_id' => $user->id, 'slug' => 'mehr', 'name' => 'Mehr kutubxonasi', 'region' => 'Toshkent shahri',
        ]);

        $qodiriy = Author::create(['name' => 'Abdulla Qodiriy', 'slug' => 'abdulla-qodiriy', 'original_name' => 'Абдулла Қодирий']);

        $this->kunlar = Book::create(['uzbek_title' => "O'tkan kunlar"]);
        $this->kunlar->authors()->attach($qodiriy);
        $this->kunlar->editions()->create(['isbn' => '9789943000017']);

        $this->chayon = Book::create(['uzbek_title' => 'Mehrobdan chayon']);
        $this->chayon->authors()->attach($qodiriy);
        $this->chayon->editions()->create([]);
    }

    private function matcher(): LibraryBookMatcher
    {
        return app(LibraryBookMatcher::class);
    }

    public function test_an_isbn_finds_the_edition_in_any_format(): void
    {
        $result = $this->matcher()->match('Something else', null, '978-9943-00001-7');

        $this->assertSame(LibraryImportRow::MATCH_ISBN, $result['match']);
        $this->assertSame($this->kunlar->id, $result['book_id']);
        $this->assertNotNull($result['edition_id']);
    }

    public function test_an_isbn_10_is_read_as_its_isbn_13(): void
    {
        $this->assertSame('9785170900589', LibraryBookMatcher::isbn13('5-17-090058-X'));
        $this->assertSame('9789943000017', LibraryBookMatcher::isbn13('9789943000017'));
        $this->assertNull(LibraryBookMatcher::isbn13('12345'));
    }

    public function test_a_title_in_cyrillic_or_with_another_apostrophe_is_the_same_title(): void
    {
        foreach (['Ўткан кунлар', 'Oʻtkan kunlar', "O`TKAN KUNLAR"] as $title) {
            $result = $this->matcher()->match($title, null, null);

            $this->assertSame(LibraryImportRow::MATCH_EXACT, $result['match'], $title);
            $this->assertSame($this->kunlar->id, $result['book_id'], $title);
        }
    }

    public function test_the_author_decides_between_two_books_with_one_title(): void
    {
        $other = Book::create(['uzbek_title' => "O'tkan kunlar"]);
        $other->editions()->create([]);
        $other->authors()->attach(Author::create(['name' => 'Boshqa Muallif', 'slug' => 'boshqa']));

        $this->assertSame(LibraryImportRow::MATCH_SUGGESTED, $this->matcher()->match("O'tkan kunlar", null, null)['match']);

        $byAuthor = $this->matcher()->match("O'tkan kunlar", 'Qodiriy A.', null);
        $this->assertSame(LibraryImportRow::MATCH_EXACT, $byAuthor['match']);
        $this->assertSame($this->kunlar->id, $byAuthor['book_id']);
    }

    public function test_an_author_that_does_not_fit_is_only_a_suggestion(): void
    {
        $result = $this->matcher()->match('Mehrobdan chayon', 'Pirimqul Qodirov', null);

        $this->assertSame(LibraryImportRow::MATCH_SUGGESTED, $result['match']);
        $this->assertSame([$this->chayon->id], $result['candidate_ids']);
    }

    public function test_a_close_title_is_suggested_and_an_unknown_one_is_not_found(): void
    {
        $this->assertSame(LibraryImportRow::MATCH_SUGGESTED, $this->matcher()->match('Mehrobdan', null, null)['match']);
        $this->assertSame(LibraryImportRow::MATCH_NONE, $this->matcher()->match('Mutlaqo boshqa kitob', null, null)['match']);
    }

    public function test_a_csv_with_its_own_headers_is_read_and_matched(): void
    {
        $import = $this->read("Muallifi;Kitob nomi;Nusxa soni;Mavjud\nAbdulla Qodiriy;O'tkan kunlar;3;1\n;;;\nQodiriy;Yo'q kitob;1;1\n", 'royxat.csv');

        $rows = $import->rows()->orderBy('row_number')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([2, 4], $rows->pluck('row_number')->all());
        $this->assertSame(["O'tkan kunlar", 3, 1, LibraryImportRow::ACCEPT], [$rows[0]->title, $rows[0]->copies, $rows[0]->available, $rows[0]->decision]);
        $this->assertSame([LibraryImportRow::MATCH_NONE, LibraryImportRow::PENDING], [$rows[1]->match, $rows[1]->decision]);
    }

    public function test_an_excel_file_without_headers_is_read_in_the_templates_order(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lib').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Mehrobdan chayon', 'Abdulla Qodiriy', 9789943000000.0, 2]));
        $writer->close();

        $import = app(LibraryImporter::class)->read($this->library, $path, 'list.xlsx');
        $row = $import->rows()->sole();

        $this->assertSame(['Mehrobdan chayon', '9789943000000', 2, null], [$row->title, $row->isbn, $row->copies, $row->available]);
        $this->assertSame($this->chayon->id, $row->book_id);
    }

    public function test_reading_a_new_file_drops_the_one_never_applied(): void
    {
        $first = $this->read("Nomi\nO'tkan kunlar\n");
        $this->read("Nomi\nMehrobdan chayon\n");

        $this->assertModelMissing($first);
        $this->assertSame(1, LibraryImport::count());
    }

    public function test_applying_adds_updates_and_leaves_undecided_lines_out(): void
    {
        $this->library->holdings()->create(['book_id' => $this->kunlar->id, 'copies_total' => 1, 'copies_available' => 0]);

        $import = $this->read("Nomi;Muallif;Soni;Mavjud\nO'tkan kunlar;Qodiriy;2;\nMehrobdan chayon;Qodiriy;3;2\nYo'q kitob;;1;1\n");

        $result = app(LibraryImporter::class)->apply($import);

        $this->assertSame(['added' => 1, 'updated' => 1, 'removed' => 0], $result);
        $holdings = $this->library->holdings()->get()->keyBy('book_id');
        // The file gives copies but not what is on the shelf: kept as it was.
        $this->assertSame([2, 0], [$holdings[$this->kunlar->id]->copies_total, $holdings[$this->kunlar->id]->copies_available]);
        $this->assertSame([3, 2], [$holdings[$this->chayon->id]->copies_total, $holdings[$this->chayon->id]->copies_available]);
        $this->assertSame(LibraryImport::APPLIED, $import->fresh()->status);
        $this->assertNotNull($this->library->fresh()->holdings_updated_at);
    }

    public function test_two_lines_for_one_book_add_up_and_a_new_book_starts_fully_on_the_shelf(): void
    {
        $import = $this->read("Nomi;Soni\nO'tkan kunlar;2\nЎткан кунлар;1\n");

        app(LibraryImporter::class)->apply($import);

        $holding = $this->library->holdings()->sole();
        $this->assertSame([3, 3], [$holding->copies_total, $holding->copies_available]);
    }

    public function test_applying_the_whole_collection_removes_what_it_does_not_name(): void
    {
        $this->library->holdings()->create(['book_id' => $this->chayon->id]);

        app(LibraryImporter::class)->apply($this->read("Nomi\nO'tkan kunlar\n"), removeMissing: true);

        $this->assertSame([$this->kunlar->id], $this->library->holdings()->pluck('book_id')->all());
    }

    public function test_applying_pings_the_site_once(): void
    {
        config(['seo.revalidate.url' => 'https://site.test/api/revalidate', 'seo.revalidate.secret' => 'secret']);
        $import = $this->read("Nomi\nO'tkan kunlar\nMehrobdan chayon\n");
        Http::fake();

        app(LibraryImporter::class)->apply($import);

        Http::assertSentCount(1);
    }

    public function test_the_owner_uploads_checks_a_line_and_applies_from_the_page(): void
    {
        $this->actingAs($this->library->user);

        $page = Livewire::test(ImportHoldings::class, ['record' => $this->library->getRouteKey()])
            ->set('upload.file', UploadedFile::fake()->createWithContent('royxat.csv', "Nomi;Muallif\nMehrobdan chayon;Pirimqul Qodirov\n"))
            ->call('read')
            ->assertHasNoErrors();

        $row = LibraryImportRow::sole();
        $this->assertSame(LibraryImportRow::PENDING, $row->decision);

        $page->callTableAction('choose', $row, data: ['candidate' => $this->chayon->id])
            ->callAction('apply', data: ['remove_missing' => false])
            ->assertRedirect(LibraryResource::getUrl('edit', ['record' => $this->library]));

        $this->assertSame([$this->chayon->id], $this->library->holdings()->pluck('book_id')->all());
    }

    public function test_an_owner_cannot_open_another_librarys_import(): void
    {
        $other = Library::create(['slug' => 'idrok', 'name' => 'Idrok', 'region' => 'Toshkent viloyati']);

        $this->actingAs($this->library->user);

        $this->get(LibraryResource::getUrl('import', ['record' => $this->library]))->assertOk();
        $this->get(LibraryResource::getUrl('import', ['record' => $other]))->assertNotFound();
    }

    public function test_the_template_has_the_headers_the_reader_looks_for(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        app(LibraryImporter::class)->template($path);

        $import = app(LibraryImporter::class)->read($this->library, $path, 'kitoblar-shablon.xlsx');

        $row = $import->rows()->sole();
        $this->assertSame(["O'tkan kunlar", 'Abdulla Qodiriy', 2, 1], [$row->title, $row->author, $row->copies, $row->available]);
    }

    private function read(string $csv, string $name = 'list.csv'): LibraryImport
    {
        $path = tempnam(sys_get_temp_dir(), 'lib');
        file_put_contents($path, $csv);

        return app(LibraryImporter::class)->read($this->library, $path, $name);
    }
}
