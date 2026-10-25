<?php

namespace App\Filament\Support;

use App\Models\Book;
use App\Services\CatalogueSync;
use Illuminate\Database\Eloquent\Builder;

/**
 * The book search behind a library's "add a book" and the import's "link
 * to another book". A library owner knows a book by title and author, or by
 * the ISBN on its back, so all three find it; each option names the author,
 * since one title often belongs to several books.
 */
final class BookPicker
{
    /** @return array<int, string> */
    public static function search(string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $like = '%'.$search.'%';
        $isbn = CatalogueSync::normalizeIsbn($search);

        return Book::approved()
            ->where(function (Builder $match) use ($like, $isbn) {
                $match->where('uzbek_title', 'like', $like)
                    ->orWhere('original_title', 'like', $like)
                    ->orWhereHas('authors', fn (Builder $author) => $author->where('name', 'like', $like));

                if ($isbn !== null) {
                    $match->orWhereHas('editions', fn (Builder $edition) => $edition->where('isbn', $isbn));
                }
            })
            ->with('authors')
            ->orderBy('uzbek_title')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Book $book) => [$book->id => self::label($book)])
            ->all();
    }

    public static function labelFor(mixed $id): ?string
    {
        $book = Book::with('authors')->find($id);

        return $book ? self::label($book) : null;
    }

    public static function label(Book $book): string
    {
        return collect([$book->uzbek_title, $book->author_names])->filter()->implode(' — ');
    }
}
