<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookDetailResource;
use App\Http\Resources\Api\BookResource;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BookController extends Controller
{
    private const PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const RELATED_LIMIT = 8;

    private const LATEST_YEAR = '(select max(e.year) from editions e where e.book_id = books.id and e.status = ?)';

    /**
     * The catalogue, a page at a time. Every filter takes a slug, the same
     * one the site routes on:
     *
     *   ?q=        title, original title, author name, or an exact ISBN
     *   ?author=   ?publisher=   ?translator=   ?category=   ?series=
     *   ?sort=     year (newest edition first, the default) | title | recent;
     *              a search with no sort of its own puts the closest title first
     *   ?page=     ?per_page= (at most 100)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Book::approved()->with(BookResource::relations());

        $this->filter($query, $request);

        if (filled($q = $this->searchTerm($request)) && ! $request->filled('sort')) {
            $this->byRelevance($query, $q);
        }

        $this->sort($query, (string) $request->query('sort', 'year'));

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('per_page', self::PER_PAGE)));

        return BookResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function show(Book $book): BookDetailResource
    {
        // A book awaiting moderation does not exist as far as the site is
        // concerned, including at its own URL.
        if (! Book::approved()->whereKey($book->id)->exists()) {
            throw new NotFoundHttpException;
        }

        $book->load([
            ...BookResource::relations(),
            'approvedEditions' => fn ($query) => $query->with(['publisher', 'translators', 'images']),
            // `taqrizlar` is constrained to approved at the query, so a
            // pending review is never loaded and so can never be rendered
            // by mistake.
            'taqrizlar' => fn ($query) => $query->approved()->with(['taqrizchi', 'edition.publisher', 'edition.translators']),
            // Approved at the query too, and the newest few: a book page is
            // not the place to read them all.
            'readerReviews' => fn ($query) => $query->approved()->limit(20),
        ]);

        return new BookDetailResource($book, $this->relatedTo($book));
    }

    private function filter(Builder $query, Request $request): void
    {
        if (filled($q = $this->searchTerm($request))) {
            $like = '%'.self::escapeLike($q).'%';
            $isbn = preg_replace('/[^0-9Xx]/', '', $q);

            $query->where(function (Builder $match) use ($like, $isbn) {
                $match->where('books.uzbek_title', 'like', $like)
                    ->orWhere('books.original_title', 'like', $like)
                    ->orWhereHas('authors', fn (Builder $a) => $a->where(fn (Builder $name) => $name->where('name', 'like', $like)->orWhere('original_name', 'like', $like)))
                    ->orWhereHas('editions', fn (Builder $e) => $e->approved()->where('title', 'like', $like));

                if (strlen($isbn) >= 10) {
                    $match->orWhereHas('editions', fn (Builder $e) => $e->approved()->where('isbn', strtoupper($isbn)));
                }
            });
        }

        if (filled($slug = $request->query('author'))) {
            $query->whereHas('authors', fn (Builder $a) => $a->where('slug', $slug));
        }

        if (filled($slug = $request->query('publisher'))) {
            $query->whereHas('editions', fn (Builder $e) => $e->approved()->whereHas('publisher', fn (Builder $p) => $p->where('slug', $slug)));
        }

        if (filled($slug = $request->query('translator'))) {
            $query->whereHas('editions', fn (Builder $e) => $e->approved()->whereHas('translators', fn (Builder $t) => $t->where('slug', $slug)));
        }

        if (filled($slug = $request->query('category'))) {
            // A parent category includes its children: "Badiiy adabiyot"
            // lists "Jahon adabiyoti" too.
            $query->whereHas('categories', fn (Builder $c) => $c->where(fn (Builder $either) => $either->where('slug', $slug)
                ->orWhereHas('parent', fn (Builder $parent) => $parent->where('slug', $slug))));
        }

        if (filled($slug = $request->query('series'))) {
            $query->whereHas('series', fn (Builder $s) => $s->where('slug', $slug));
        }
    }

    /**
     * The title typed in full, then a title that starts with it, then one
     * that contains it; a match only on an author, an edition's own title
     * or an ISBN comes after. So "1984" is Orwell's before "Taym mashin
     * 1984", however new the latter. Ties keep the usual order.
     */
    private function byRelevance(Builder $query, string $q): void
    {
        $prefix = self::escapeLike($q).'%';
        $anywhere = '%'.self::escapeLike($q).'%';

        $query->orderByRaw(
            'case
                when books.uzbek_title = ? or books.original_title = ? then 0
                when books.uzbek_title like ? or books.original_title like ? then 1
                when books.uzbek_title like ? or books.original_title like ? then 2
                else 3
            end',
            [$q, $q, $prefix, $prefix, $anywhere, $anywhere],
        );
    }

    private static function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'title' => $query->orderBy('books.uzbek_title'),
            'recent' => $query->orderByDesc('books.id'),
            // Newest edition first; books with no known year go last.
            default => $query
                ->orderByRaw(self::LATEST_YEAR.' is null', [ModerationStatus::Approved->value])
                ->orderByRaw(self::LATEST_YEAR.' desc', [ModerationStatus::Approved->value])
                ->orderBy('books.sort_order')
                ->orderBy('books.id'),
        };
    }

    /**
     * "What to read next", strongest signal first: books the source
     * catalogue itself relates to this one, then the rest of its series,
     * then more by the same author, then more of its genre.
     *
     * @return Collection<int, Book>
     */
    private function relatedTo(Book $book): Collection
    {
        $candidates = collect();
        $add = function (Builder $query) use (&$candidates, $book) {
            if ($candidates->count() >= self::RELATED_LIMIT) {
                return;
            }

            $query->approved()
                ->whereKeyNot([$book->id, ...$candidates->pluck('id')])
                ->with(BookResource::relations())
                ->limit(self::RELATED_LIMIT - $candidates->count())
                ->get()
                ->each(fn (Book $related) => $candidates->push($related));
        };

        $add($book->relatedBooks()->getQuery()->select('books.*'));

        if ($book->series_id !== null) {
            $add(Book::where('series_id', $book->series_id)->orderBy('series_position'));
        }

        $authorIds = $book->authors->pluck('id');
        if ($authorIds->isNotEmpty()) {
            $add(Book::whereHas('authors', fn (Builder $a) => $a->whereKey($authorIds))->latest('books.id'));
        }

        $genre = $book->categories->firstWhere('pivot.is_primary', true) ?? $book->categories->first();
        if ($genre !== null) {
            $add(Book::whereHas('categories', fn (Builder $c) => $c->whereKey($genre->id))->latest('books.id'));
        }

        return $candidates;
    }
}
