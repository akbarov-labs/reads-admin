<?php

namespace App\Enums;

use App\Models\Book;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Something missing or wrong in a book's record, as a reader of its page
 * would see it. Each case narrows a query of books to the ones that have
 * it, so books:quality and the "Needs attention" filter count the same
 * thing. Edition checks look at approved editions only: the rest are not
 * on the site.
 */
enum BookProblem: string implements HasLabel
{
    case NoCover = 'no_cover';
    case NoDescription = 'no_description';
    case StoreBucketPublisher = 'store_bucket_publisher';
    case NoCategories = 'no_categories';
    case NoSourceLanguage = 'no_source_language';
    case NoAuthor = 'no_author';
    case EditionWithoutIsbn = 'edition_without_isbn';

    /**
     * Names a shop files books under when it doesn't know the publisher.
     * They are shelves in the shop, not publishers; add others here.
     */
    public const STORE_BUCKETS = [
        'Boshqa nashriyotlar kitoblari', // Asaxiy: "books from other publishers"
    ];

    public function getLabel(): string
    {
        return match ($this) {
            self::NoCover => 'No cover',
            self::NoDescription => 'No description',
            self::StoreBucketPublisher => 'Publisher is a shop bucket',
            self::NoCategories => 'No shelves',
            self::NoSourceLanguage => 'No original language',
            self::NoAuthor => 'No author',
            self::EditionWithoutIsbn => 'Edition without ISBN',
        };
    }

    /** @param  Builder<Book>  $books */
    public function apply(Builder $books): void
    {
        match ($this) {
            self::NoCover => $books->whereDoesntHave('editions', fn (Builder $editions) => $editions->approved()
                ->whereNotNull('editions.cover_path')
                ->where('editions.cover_path', '!=', '')),
            // Neither the book nor any edition has text: the page shows none.
            self::NoDescription => $books->where(fn (Builder $book) => self::blank($book, 'books.description'))
                ->whereDoesntHave('editions', fn (Builder $editions) => $editions->approved()
                    ->whereNotNull('editions.description')
                    ->whereRaw("trim(editions.description) != ''")),
            self::StoreBucketPublisher => $books->whereHas('editions', fn (Builder $editions) => $editions->approved()
                ->whereHas('publisher', fn (Builder $publisher) => $publisher->whereIn('name', self::STORE_BUCKETS))),
            self::NoCategories => $books->whereDoesntHave('categories'),
            self::NoSourceLanguage => $books->where(fn (Builder $book) => self::blank($book, 'books.source_language')),
            self::NoAuthor => $books->whereDoesntHave('authors'),
            self::EditionWithoutIsbn => $books->whereHas('editions', fn (Builder $editions) => $editions->approved()
                ->where(fn (Builder $edition) => self::blank($edition, 'editions.isbn'))),
        };
    }

    /**
     * Books with at least one problem.
     *
     * @param  Builder<Book>  $books
     */
    public static function applyAny(Builder $books): void
    {
        $books->where(function (Builder $books) {
            foreach (self::cases() as $problem) {
                $books->orWhere(fn (Builder $book) => $problem->apply($book));
            }
        });
    }

    private static function blank(Builder $query, string $column): void
    {
        $query->whereNull($column)->orWhereRaw("trim({$column}) = ''");
    }
}
