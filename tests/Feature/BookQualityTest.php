<?php

namespace Tests\Feature;

use App\Enums\BookProblem;
use App\Enums\ModerationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\BookResource\Pages\ListBooks;
use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/**
 * books:quality and the Books list's "Needs attention" filter: the same
 * checks, counted in one and listed in the other.
 */
class BookQualityTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    /** ISBNs are unique, so each book gets the next one. */
    private int $isbns = 0;

    /** A book with nothing missing; each test breaks one thing. */
    private function wholeBook(array $book = [], array $edition = [], ?array $authors = null): Book
    {
        $work = $this->catalogueBook(
            book: ['description' => 'Kuba baliqchisi Santyago haqida qissa.', ...$book],
            edition: [
                'publisher_id' => $this->publisher()->id,
                'isbn' => '97899432'.str_pad((string) ++$this->isbns, 5, '0', STR_PAD_LEFT),
                'cover_path' => 'covers/chol.jpg',
                ...$edition,
            ],
            authors: $authors,
        );

        $work->categories()->attach(Category::firstOrCreate(['slug' => 'jahon'], ['name' => 'Jahon adabiyoti'])->id);

        return $work;
    }

    /** @return list<int> */
    private function idsWith(BookProblem $problem): array
    {
        return Book::approved()->tap($problem->apply(...))->pluck('books.id')->all();
    }

    /** @return array<string, array{0: BookProblem, 1: \Closure(self): Book}> */
    public static function brokenBooks(): array
    {
        return [
            'no cover' => [BookProblem::NoCover, fn (self $t) => $t->wholeBook(edition: ['cover_path' => null])],
            'blank description' => [BookProblem::NoDescription, fn (self $t) => $t->wholeBook(book: ['description' => '  '])],
            'shop bucket' => [BookProblem::StoreBucketPublisher, fn (self $t) => $t->wholeBook(edition: ['publisher_id' => $t->publisher('Boshqa nashriyotlar kitoblari')->id])],
            'no shelves' => [BookProblem::NoCategories, fn (self $t) => tap($t->wholeBook(), fn (Book $book) => $book->categories()->detach())],
            'no original language' => [BookProblem::NoSourceLanguage, fn (self $t) => $t->wholeBook(book: ['source_language' => null])],
            'no author' => [BookProblem::NoAuthor, fn (self $t) => $t->wholeBook(authors: [])],
            'no isbn' => [BookProblem::EditionWithoutIsbn, fn (self $t) => $t->wholeBook(edition: ['isbn' => null])],
        ];
    }

    #[DataProvider('brokenBooks')]
    public function test_each_problem_finds_its_book_and_nothing_else(BookProblem $problem, \Closure $break): void
    {
        $whole = $this->wholeBook(book: ['uzbek_title' => 'Butun kitob']);
        $broken = $break($this);

        foreach (BookProblem::cases() as $check) {
            $this->assertSame($check === $problem ? [$broken->id] : [], $this->idsWith($check), $check->getLabel());
        }

        $this->assertNotContains($whole->id, Book::query()->tap(BookProblem::applyAny(...))->pluck('id'));
    }

    public function test_an_editions_description_or_cover_counts_for_the_book_but_not_an_unapproved_one(): void
    {
        $fromEdition = $this->wholeBook(book: ['description' => null], edition: ['description' => 'Kuba baliqchisi haqida.']);
        $pendingOnly = $this->wholeBook(book: ['description' => null], edition: ['cover_path' => null]);
        $this->edition($pendingOnly, ['description' => 'Hali tasdiqlanmagan.', 'cover_path' => 'covers/x.jpg', 'isbn' => '9789943000000', 'status' => ModerationStatus::Pending]);

        $this->assertSame([$pendingOnly->id], $this->idsWith(BookProblem::NoDescription));
        $this->assertSame([$pendingOnly->id], $this->idsWith(BookProblem::NoCover));
        $this->assertNotContains($fromEdition->id, $this->idsWith(BookProblem::NoCover));
    }

    public function test_the_command_counts_published_books_lists_examples_and_changes_nothing(): void
    {
        $this->wholeBook();
        $noCover = $this->wholeBook(edition: ['cover_path' => null]);
        $twoProblems = $this->wholeBook(book: ['source_language' => null], edition: ['isbn' => null]);
        $this->wholeBook(book: ['status' => ModerationStatus::Pending], edition: ['cover_path' => null]);

        $before = [DB::table('books')->get(), DB::table('editions')->get()];

        $this->assertSame(0, Artisan::call('books:quality'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression("/No cover\s*\|\s*1\s*\|\s*33\.3%\s*\|\s*{$noCover->id}\s*\|/", $output);
        $this->assertMatchesRegularExpression("/No original language\s*\|\s*1\s*\|.*\|\s*{$twoProblems->id}\s*\|/", $output);
        $this->assertMatchesRegularExpression('/No author\s*\|\s*0\s*\|/', $output);
        $this->assertStringContainsString('3 published books. 2 have at least one problem (66.7%), 1 have two or more (33.3%).', $output);
        $this->assertEquals($before, [DB::table('books')->get(), DB::table('editions')->get()]);
    }

    public function test_the_command_shows_at_most_ten_examples(): void
    {
        $ids = collect(range(1, 12))->map(fn () => $this->wholeBook(book: ['source_language' => ''])->id);

        Artisan::call('books:quality');

        $this->assertMatchesRegularExpression(
            '/No original language\s*\|\s*12\s*\|\s*100%\s*\|\s*'.$ids->take(10)->implode(', ').'\s*\|/',
            Artisan::output(),
        );
    }

    public function test_the_needs_attention_filter_lists_books_with_any_problem_or_one_chosen_problem(): void
    {
        $whole = $this->wholeBook();
        $noCover = $this->wholeBook(edition: ['cover_path' => null]);
        $noAuthor = $this->wholeBook(authors: []);

        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'role' => UserRole::Admin]));

        Livewire::test(ListBooks::class)
            ->filterTable('needs_attention', 'any')
            ->assertCanSeeTableRecords([$noCover, $noAuthor])
            ->assertCanNotSeeTableRecords([$whole])
            ->filterTable('needs_attention', BookProblem::NoAuthor->value)
            ->assertCanSeeTableRecords([$noAuthor])
            ->assertCanNotSeeTableRecords([$whole, $noCover])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$whole, $noCover, $noAuthor]);
    }
}
