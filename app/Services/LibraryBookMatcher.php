<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Edition;
use App\Models\LibraryImportRow;
use App\Support\SearchKeys;
use App\Support\UzbekText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds the catalogue book a line of a library's list means. The first rule
 * that hits wins:
 *
 *  1. ISBN: an approved edition with it, which names the printing too.
 *  2. Exact: one approved book whose title is the line's title once case,
 *     alphabet and apostrophes are set aside, and whose author fits when
 *     the line names one.
 *  3. Suggested: up to three books the title could be, for the owner to
 *     pick from.
 *  4. None.
 *
 * Only 1 and 2 are taken without asking. A library's spelling is not good
 * enough to decide between two books on its own.
 */
class LibraryBookMatcher
{
    public const MAX_CANDIDATES = 3;

    /**
     * @return array{match: string, book_id: ?int, edition_id: ?int, candidate_ids: list<int>}
     */
    public function match(?string $title, ?string $author, ?string $isbn): array
    {
        if ($edition = $this->byIsbn($isbn)) {
            return $this->result(LibraryImportRow::MATCH_ISBN, $edition->book_id, $edition->id);
        }

        $titleKey = UzbekText::searchKey($title);

        if ($titleKey === null) {
            return $this->result(LibraryImportRow::MATCH_NONE);
        }

        $sameTitle = $this->sameTitle($titleKey);
        $byAuthor = $author !== null && trim($author) !== ''
            ? $sameTitle->filter(fn (Book $book) => $this->authorFits($book, $author))
            : $sameTitle;

        if ($byAuthor->count() === 1) {
            return $this->result(LibraryImportRow::MATCH_EXACT, $byAuthor->first()->id);
        }

        $candidates = ($byAuthor->isNotEmpty() ? $byAuthor : $sameTitle)
            ->concat($this->similarTitles($titleKey, $author))
            ->unique('id')
            ->take(self::MAX_CANDIDATES)
            ->pluck('id')
            ->values()
            ->all();

        if ($candidates === []) {
            return $this->result(LibraryImportRow::MATCH_NONE);
        }

        // The first candidate is preselected, so "Accept" is one click when
        // it is the right one.
        return $this->result(LibraryImportRow::MATCH_SUGGESTED, $candidates[0], null, $candidates);
    }

    /**
     * Digits and X only. An ISBN-10 is turned into its ISBN-13, which is
     * what the catalogue holds: older Uzbek printings carry only the ten.
     */
    public static function isbn13(?string $raw): ?string
    {
        $digits = strtoupper((string) preg_replace('/[^0-9Xx]/', '', (string) $raw));

        if (preg_match('/^\d{13}$/', $digits) === 1) {
            return $digits;
        }

        if (preg_match('/^\d{9}[\dX]$/', $digits) !== 1) {
            return null;
        }

        $twelve = '978'.substr($digits, 0, 9);
        $sum = 0;

        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $twelve.((10 - $sum % 10) % 10);
    }

    private function byIsbn(?string $isbn): ?Edition
    {
        $isbn = self::isbn13($isbn);

        if ($isbn === null) {
            return null;
        }

        return Edition::query()
            ->approved()
            ->where('isbn', $isbn)
            ->whereHas('book', fn (Builder $book) => $book->approved())
            ->first();
    }

    /** @return Collection<int, Book> */
    private function sameTitle(string $titleKey): Collection
    {
        // A book's key is its Uzbek title then its original title, so the
        // query narrows to keys starting with this title, and the comparison
        // here keeps only the books whose own title is exactly it.
        return Book::query()
            ->approved()
            ->where(fn (Builder $key) => $key->where('search_key', $titleKey)->orWhere('search_key', 'like', $titleKey.' %'))
            ->with('authors.aliases')
            ->limit(20)
            ->get()
            ->filter(fn (Book $book) => UzbekText::searchKey($book->uzbek_title) === $titleKey
                || UzbekText::searchKey($book->original_title) === $titleKey)
            ->values();
    }

    /** @return Collection<int, Book> */
    private function similarTitles(string $titleKey, ?string $author): Collection
    {
        $terms = SearchKeys::terms($titleKey);

        if ($terms === []) {
            return collect();
        }

        $query = Book::query()->approved()->with('authors.aliases');
        SearchKeys::match($query, 'search_key', $terms);
        SearchKeys::rank($query, 'search_key', $terms);

        $books = $query->limit(10)->get();

        // Books by the named author first.
        return $author === null ? $books : $books->sortByDesc(fn (Book $book) => $this->authorFits($book, $author))->values();
    }

    /**
     * Whether the line's author could be one of the book's: they share a
     * word of four letters or more, so "Qodiriy A.", "Abdulla Qodiriy" and
     * "Абдулла Қодирий" all fit, and initials alone do not.
     */
    private function authorFits(Book $book, string $author): bool
    {
        $words = array_filter(explode(' ', UzbekText::searchKey($author) ?? ''), fn (string $w) => mb_strlen($w) >= 4);

        if ($words === []) {
            return true;
        }

        foreach ($book->authors as $bookAuthor) {
            $names = collect([$bookAuthor->name, $bookAuthor->original_name])
                ->concat($bookAuthor->aliases->pluck('name'))
                ->map(fn (?string $name) => explode(' ', UzbekText::searchKey($name) ?? ''))
                ->flatten();

            if ($names->intersect($words)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $candidates
     * @return array{match: string, book_id: ?int, edition_id: ?int, candidate_ids: list<int>}
     */
    private function result(string $match, ?int $bookId = null, ?int $editionId = null, array $candidates = []): array
    {
        return [
            'match' => $match,
            'book_id' => $bookId,
            'edition_id' => $editionId,
            'candidate_ids' => $candidates,
        ];
    }
}
