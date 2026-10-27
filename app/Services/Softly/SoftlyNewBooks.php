<?php

namespace App\Services\Softly;

use App\Enums\ModerationStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\LibraryImportRow;
use App\Models\SoftlyBook;
use App\Services\CatalogueRecords;
use App\Services\SiteRevalidator;
use App\Support\UzbekText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Puts the books the Softly libraries hold, and the catalogue lacks, into
 * the catalogue, so the libraries' lists on the site are whole.
 *
 * A title with no near match at all is made a book on its own: the title,
 * its author (found under any spelling, or made), and one edition with no
 * ISBN, since Softly has none. A title with near matches waits for an admin
 * on the "Softly matches" page, who links it, makes it a book, or skips it:
 * "Mehrobdan chayon (roman)" made beside "Mehrobdan chayon" would be a
 * duplicate nobody asked for.
 *
 * The same book in several libraries is several rows, one per library, with
 * the same title and author. Every decision is for all of them: one book,
 * linked once. When an ISBN for it turns up later (the bot, an Asaxiy
 * import), it joins this book as another edition, by author and title.
 *
 * Nothing here asks Softly for anything; the next sync turns the links
 * into library holdings.
 */
class SoftlyNewBooks
{
    /** @var list<array{0: string, 1: int}>|null every author's name keys, for near spellings */
    private ?array $authorKeys = null;

    public function __construct(
        private readonly CatalogueRecords $records,
        private readonly SiteRevalidator $revalidator,
    ) {}

    /**
     * Titles with no near match, waiting to be made books: one entry per
     * book, holding its row in every library that lists it.
     *
     * @return \Illuminate\Support\Collection<string, Collection<int, SoftlyBook>>
     */
    public function waiting(): \Illuminate\Support\Collection
    {
        return SoftlyBook::query()
            ->whereNull('book_id')
            ->where('match', LibraryImportRow::MATCH_NONE)
            ->where('skipped', false)
            ->orderBy('id')
            ->get()
            ->filter(fn (SoftlyBook $row) => self::title($row) !== null)
            ->groupBy(fn (SoftlyBook $row) => self::key($row));
    }

    /**
     * Makes every waiting title a book, or links it to one that turned out
     * to have its exact author and title after all.
     *
     * @return array{created: int, found: int}
     */
    public function createWaiting(): array
    {
        $done = ['created' => 0, 'found' => 0];

        // One site refresh at the end rather than two per book.
        Model::withoutEvents(function () use (&$done) {
            foreach ($this->waiting() as $rows) {
                [, $created] = $this->settle($rows, self::title($rows->first()), $rows->first()->author);
                $done[$created ? 'created' : 'found']++;
            }
        });

        if ($done['created'] > 0) {
            $this->revalidator->everything();
        }

        return $done;
    }

    /** From the review: a new book for this title, under the title and author the admin settled on. */
    public function create(SoftlyBook $row, string $title, ?string $author): Book
    {
        [$book] = $this->settle(
            $this->sameTitle($row),
            UzbekText::clean($title) ?? self::title($row) ?? $row->name,
            UzbekText::clean($author),
        );

        return $book;
    }

    /** From the review: this title is that catalogue book. */
    public function link(SoftlyBook $row, int $bookId): void
    {
        SoftlyBook::whereKey($this->sameTitle($row)->modelKeys())->update([
            'book_id' => $bookId,
            'match' => LibraryImportRow::MATCH_MANUAL,
            'candidate_ids' => null,
            'skipped' => false,
        ]);
    }

    /** From the review: not a book to add. */
    public function skip(SoftlyBook $row): void
    {
        SoftlyBook::whereKey($this->sameTitle($row)->modelKeys())->update(['skipped' => true]);
    }

    /** From the review: back to the list after a skip. */
    public function unskip(SoftlyBook $row): void
    {
        SoftlyBook::whereKey($this->sameTitle($row)->modelKeys())->update(['skipped' => false]);
    }

    /**
     * From the review: takes back a book made from Softly, and skips its
     * title so it is not made again. Only while the book is as it was made
     * (one edition, no ISBN), so nothing an editor added since goes with it.
     */
    public function remove(SoftlyBook $row): bool
    {
        $book = $row->book;

        if ($row->match !== SoftlyBook::MATCH_CREATED || $book === null
            || $book->editions()->count() > 1 || $book->editions()->whereNotNull('isbn')->exists()) {
            return false;
        }

        DB::transaction(function () use ($book) {
            SoftlyBook::where('book_id', $book->id)->update([
                'book_id' => null,
                'match' => LibraryImportRow::MATCH_NONE,
                'candidate_ids' => null,
                'skipped' => true,
            ]);

            $book->delete();
        });

        return true;
    }

    /**
     * What createWaiting() would do, for a look before it runs: how many
     * books; the author names the catalogue has under no spelling, with how
     * many books each would get; and the names taken as a near spelling of
     * an author it has.
     *
     * @return array{books: int, rows: int, new_authors: array<string, int>, respelled: array<string, string>, sample: list<string>}
     */
    public function preview(): array
    {
        $waiting = $this->waiting();
        $newAuthors = [];
        $respelled = [];

        foreach ($waiting as $rows) {
            $name = SoftlyAuthorName::parse($rows->first()->author);

            if ($name['name'] === null) {
                continue;
            }

            [$author, $close] = $this->existingAuthor($name) ?? [null, false];

            if ($author === null) {
                $newAuthors[$name['name']] = ($newAuthors[$name['name']] ?? 0) + 1;
            } elseif ($close) {
                $respelled[$name['name']] = $author->name;
            }
        }

        arsort($newAuthors);

        return [
            'books' => $waiting->count(),
            'rows' => $waiting->sum(fn (Collection $rows) => $rows->count()),
            'new_authors' => $newAuthors,
            'respelled' => $respelled,
            'sample' => $waiting->take(10)
                ->map(fn (Collection $rows) => collect([self::title($rows->first()), self::author($rows->first())])->filter()->implode(' — '))
                ->values()
                ->all(),
        ];
    }

    /**
     * One book for these rows: the catalogue's own when it has this author
     * and title, else a new one. Links every row to it.
     *
     * @param  Collection<int, SoftlyBook>  $rows
     * @return array{0: Book, 1: bool} the book, and whether it is new
     */
    private function settle(Collection $rows, string $title, ?string $author): array
    {
        $name = SoftlyAuthorName::parse($author);

        return DB::transaction(function () use ($rows, $title, $name) {
            $author = $name['name'] === null ? null
                : ($this->existingAuthor($name)[0] ?? $this->records->author($name['name']));
            $book = $this->records->findBook($author?->name, $title);
            $created = $book === null;

            if ($created) {
                $book = Book::create(['uzbek_title' => $title, 'status' => ModerationStatus::Approved]);

                if ($author !== null) {
                    $book->authors()->attach($author->id, ['position' => 0]);
                }

                $book->editions()->create(['status' => ModerationStatus::Approved]);
            }

            SoftlyBook::whereKey($rows->modelKeys())->update([
                'book_id' => $book->id,
                'match' => $created ? SoftlyBook::MATCH_CREATED : LibraryImportRow::MATCH_EXACT,
                'candidate_ids' => null,
            ]);

            return [$book, $created];
        });
    }

    /**
     * The catalogue's author for a name: under any of its spellings, or else
     * one letter off a name of ten letters or more ("Abdullah Qodiriy" for
     * Abdulla Qodiriy), which is a typo far more often than another person.
     *
     * @param  array{name: string, spellings: list<string>}  $name
     * @return array{0: Author, 1: bool}|null the author, and whether found only by the near spelling
     */
    private function existingAuthor(array $name): ?array
    {
        foreach ($name['spellings'] as $spelling) {
            if ($author = $this->records->findAuthor($spelling)) {
                return [$author, false];
            }
        }

        $wanted = UzbekText::key($name['name']);

        if (strlen($wanted) < 10) {
            return null;
        }

        $this->authorKeys ??= DB::table('authors')->orderBy('id')->get(['id', 'name', 'original_name'])
            ->flatMap(fn (object $row) => collect([$row->name, $row->original_name])->filter()
                ->map(fn (string $spelling) => [UzbekText::key($spelling), (int) $row->id]))
            ->all();

        foreach ($this->authorKeys as [$key, $id]) {
            if (abs(strlen($key) - strlen($wanted)) <= 1 && levenshtein($key, $wanted) === 1) {
                return [Author::findOrFail($id), true];
            }
        }

        return null;
    }

    /**
     * The row and every other unlinked one with its title and author, in
     * any library.
     *
     * @return Collection<int, SoftlyBook>
     */
    private function sameTitle(SoftlyBook $row): Collection
    {
        $key = self::key($row);

        return SoftlyBook::query()
            ->whereNull('book_id')
            ->get()
            ->filter(fn (SoftlyBook $other) => self::key($other) === $key)
            ->values();
    }

    /** Same title and author, in any alphabet or spelling of the apostrophe. */
    public static function key(SoftlyBook $row): string
    {
        return CatalogueRecords::bookKey(self::author($row), self::title($row) ?? '');
    }

    private static function title(SoftlyBook $row): ?string
    {
        return UzbekText::clean($row->name);
    }

    /** The author to credit, without words of respect, brackets or a non-author (SoftlyAuthorName). */
    private static function author(SoftlyBook $row): ?string
    {
        return SoftlyAuthorName::parse($row->author)['name'];
    }
}
