<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\Translator;
use App\Support\UzbekText;
use Illuminate\Support\Facades\DB;

/**
 * Finds the author, publisher, translator or book a name refers to, or makes
 * it — under the same spelling-insensitive keys the Asaxiy seeder uses
 * (UzbekText::key / publisherKey, and bookKey below), so a book arriving
 * from the bot lands on the row an Asaxiy import or an admin already made
 * rather than beside it.
 *
 * The seeder holds the whole catalogue in memory because it handles ten
 * thousand listings at once. This one serves a single request, so it asks
 * the database for an exact name first and only falls back to comparing
 * keys across the table (built once, on the first miss) when that finds
 * nothing — which is what catches "Abdulla Qodiriy" against a row stored as
 * "Абдулла Қодирий".
 *
 * Callers that must not ping the public site per row wrap their writes in
 * Model::withoutEvents(); nothing here does it for them.
 */
class CatalogueRecords
{
    /** @var array<string, array<string, int>> table => key => id of the first row with that key */
    private array $index = [];

    /** A book is its first author plus its title, in any alphabet. */
    public static function bookKey(?string $firstAuthor, string $title): string
    {
        return UzbekText::key((string) $firstAuthor).'|'.UzbekText::key($title);
    }

    public function author(string $name): Author
    {
        $id = $this->idFor('authors', ['name', 'original_name'], $name, UzbekText::key(...))
            ?? $this->mergedAuthorId($name);

        if ($id !== null) {
            return Author::findOrFail($id);
        }

        $author = Author::create(['name' => $name, 'slug' => Author::generateUniqueSlug($name)]);
        $this->index['authors'][UzbekText::key($name)] ??= $author->id;

        return $author;
    }

    public function publisher(string $name): Publisher
    {
        $id = $this->idFor('publishers', ['name'], $name, UzbekText::publisherKey(...))
            ?? $this->idFor('publisher_aliases', ['name'], $name, UzbekText::publisherKey(...), 'publisher_id');

        if ($id !== null) {
            return Publisher::findOrFail($id);
        }

        $publisher = Publisher::create(['name' => $name, 'slug' => Publisher::generateUniqueSlug($name)]);
        $this->index['publishers'][UzbekText::publisherKey($name)] ??= $publisher->id;

        return $publisher;
    }

    /** A shell (name and slug, no login) when new, as in the seeder: an admin attaches an account if the person ever claims it. */
    public function translator(string $name): Translator
    {
        $id = $this->idFor('translators', ['name'], $name, UzbekText::key(...));

        if ($id !== null) {
            return Translator::findOrFail($id);
        }

        $translator = Translator::create(['name' => $name, 'slug' => Translator::generateUniqueSlug($name)]);
        $this->index['translators'][UzbekText::key($name)] ??= $translator->id;

        return $translator;
    }

    /**
     * The book whose first-credited author and title have this key; the
     * oldest if there are several. Null when there is none — a book by the
     * same author with a different title is a different book.
     */
    public function findBook(?string $firstAuthor, string $title): ?Book
    {
        $wanted = self::bookKey($firstAuthor, $title);

        if ($firstAuthor === null) {
            $candidates = DB::table('books')
                ->whereNotExists(fn ($authored) => $authored->select(DB::raw(1))->from('book_author')->whereColumn('book_author.book_id', 'books.id'))
                ->orderBy('books.id')
                ->get(['books.id', 'books.uzbek_title']);
        } else {
            $authorIds = $this->authorIds($firstAuthor);

            if ($authorIds === []) {
                return null;
            }

            $bookIds = DB::table('book_author')->whereIn('author_id', $authorIds)->pluck('book_id')->unique()->all();

            // First-credited, not merely credited: a collection edited by
            // two people is a different book from either one's own.
            $firstAuthors = [];
            foreach (DB::table('book_author')->whereIn('book_id', $bookIds)->orderBy('book_id')->orderBy('position')->orderBy('id')->get(['book_id', 'author_id']) as $row) {
                $firstAuthors[$row->book_id] ??= $row->author_id;
            }

            $ownBooks = array_keys(array_filter($firstAuthors, fn (int $author) => in_array($author, $authorIds, true)));

            $candidates = DB::table('books')->whereIn('id', $ownBooks)->orderBy('id')->get(['id', 'uzbek_title']);
        }

        foreach ($candidates as $candidate) {
            if (self::bookKey($firstAuthor, $candidate->uzbek_title) === $wanted) {
                return Book::find($candidate->id);
            }
        }

        return null;
    }

    /** @return list<int> */
    private function authorIds(string $name): array
    {
        $exact = DB::table('authors')->where(fn ($match) => $match->where('name', $name)->orWhere('original_name', $name))->pluck('id')->all();

        if ($exact !== []) {
            return array_map(intval(...), $exact);
        }

        $id = $this->idFor('authors', ['name', 'original_name'], $name, UzbekText::key(...))
            ?? $this->mergedAuthorId($name);

        return $id === null ? [] : [$id];
    }

    /** The author a spelling was merged into (AuthorMerger), so the duplicate is not made again. */
    private function mergedAuthorId(string $name): ?int
    {
        return $this->idFor('author_aliases', ['name'], $name, UzbekText::key(...), 'author_id');
    }

    /**
     * @param  list<string>  $columns  every spelling the table keeps
     * @param  callable(string): string  $key
     * @param  string  $idColumn  the id to answer with, when it is not the row's own
     */
    private function idFor(string $table, array $columns, string $name, callable $key, string $idColumn = 'id'): ?int
    {
        $exact = DB::table($table)->where(function ($match) use ($columns, $name) {
            foreach ($columns as $column) {
                $match->orWhere($column, $name);
            }
        })->orderBy('id')->value($idColumn);

        if ($exact !== null) {
            return (int) $exact;
        }

        $wanted = $key($name);

        if ($wanted === '') {
            return null;
        }

        if (! isset($this->index[$table])) {
            $this->index[$table] = [];

            foreach (DB::table($table)->select(array_unique(['id', $idColumn, ...$columns]))->orderBy('id')->cursor() as $row) {
                foreach ($columns as $column) {
                    if (filled($row->{$column})) {
                        $this->index[$table][$key($row->{$column})] ??= (int) $row->{$idColumn};
                    }
                }
            }
        }

        return $this->index[$table][$wanted] ?? null;
    }
}
