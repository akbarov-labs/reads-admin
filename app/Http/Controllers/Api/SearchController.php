<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\AuthorAlias;
use App\Models\Book;
use App\Models\Publisher;
use App\Models\Translator;
use App\Support\SearchKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The site's search-as-you-type box: the best few books, authors,
 * translators and publishers for ?q=, each group with its total, in one
 * round trip. ?limit= is per group, at most 10.
 *
 * Only what the site would show is found: approved books, the authors and
 * publishers of one, and translators credited on one or with a profile. Matching goes through
 * `search_key` (SearchKeys), so "otkan", "O‘tkan" and "Ўткан" are one query.
 */
class SearchController extends Controller
{
    private const LIMIT = 5;

    private const MAX_LIMIT = 10;

    public function __invoke(Request $request): JsonResponse
    {
        $terms = SearchKeys::terms((string) $request->query('q'));
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query('limit', self::LIMIT)));

        if ($terms === []) {
            $empty = ['items' => [], 'total' => 0];

            return response()->json(['books' => $empty, 'authors' => $empty, 'translators' => $empty, 'publishers' => $empty]);
        }

        return response()->json([
            'books' => $this->books($terms, $limit),
            'authors' => $this->authors($terms, $limit),
            'translators' => $this->translators($terms, $limit),
            'publishers' => $this->publishers($terms, $limit),
        ]);
    }

    /** @param  list<string>  $terms */
    private function books(array $terms, int $limit): array
    {
        $query = Book::approved();
        SearchKeys::match($query, 'books.search_key', $terms);
        $total = (clone $query)->count();

        SearchKeys::rank($query, 'books.search_key', $terms);
        $books = $query->orderByRaw('length(books.uzbek_title)')->orderBy('books.id')
            ->with(['authors', 'approvedEditions'])
            ->limit($limit)->get();

        return [
            'items' => $books->map(fn (Book $book) => [
                'id' => (string) $book->id,
                'uzbekTitle' => $book->uzbek_title,
                'originalTitle' => $book->original_title,
                'author' => (string) $book->author_names,
                'year' => $book->primary_edition?->year,
                'coverUrl' => $book->primary_edition?->cover_url,
            ])->values(),
            'total' => $total,
        ];
    }

    /**
     * An author is found by a name they were merged from too: someone who
     * searches the old spelling lands on the one page that is left.
     *
     * @param  list<string>  $terms
     */
    private function authors(array $terms, int $limit): array
    {
        // Counted once and joined in, as in AuthorController: per-author
        // whereHas/withCount is the plan that took 30 s on SQLite.
        $counts = DB::table('book_author')
            ->select('book_author.author_id', DB::raw('count(*) as books_count'))
            ->whereIn('book_author.book_id', Book::approved()->select('books.id'))
            ->groupBy('book_author.author_id');

        $query = Author::query()
            ->joinSub($counts, 'published', 'published.author_id', '=', 'authors.id')
            ->select('authors.*', 'published.books_count')
            ->where(function (Builder $either) use ($terms) {
                $either->where(fn (Builder $own) => SearchKeys::match($own, 'authors.search_key', $terms))
                    ->orWhereIn('authors.id', AuthorAlias::select('author_id')->where(fn (Builder $alias) => SearchKeys::match($alias, 'search_key', $terms)));
            });
        $total = (clone $query)->count();

        SearchKeys::rank($query, 'authors.search_key', $terms);
        $authors = $query->orderByDesc('published.books_count')->orderBy('authors.name')->limit($limit)->get();

        return [
            'items' => $authors->map(fn (Author $author) => [
                'slug' => $author->slug,
                'name' => $author->name,
                'originalName' => $author->original_name,
                'portraitUrl' => $author->portrait_url,
                'bookCount' => (int) $author->books_count,
            ])->values(),
            'total' => $total,
        ];
    }

    /** @param  list<string>  $terms */
    private function translators(array $terms, int $limit): array
    {
        $published = fn (Builder $editions) => $editions->approved()
            ->whereHas('book', fn (Builder $book) => $book->where('books.status', ModerationStatus::Approved));

        // A translator with a profile of their own has a page before their
        // first book is in; one known only from a book's credits does not.
        $query = Translator::where(fn (Builder $listed) => $listed->whereNotNull('translators.user_id')->orWhereHas('editions', $published));
        SearchKeys::match($query, 'translators.search_key', $terms);
        $total = (clone $query)->count();

        SearchKeys::rank($query, 'translators.search_key', $terms);
        $translators = $query
            ->withCount(['editions as books_count' => fn (Builder $editions) => $published($editions->select(DB::raw('count(distinct editions.book_id)')))])
            ->orderByDesc('books_count')->orderBy('translators.name')
            ->limit($limit)->get();

        return [
            'items' => $translators->map(fn (Translator $translator) => [
                'slug' => $translator->slug,
                'name' => $translator->name,
                'avatarUrl' => $translator->avatar_url,
                'bookCount' => (int) $translator->books_count,
            ])->values(),
            'total' => $total,
        ];
    }

    /** @param  list<string>  $terms */
    private function publishers(array $terms, int $limit): array
    {
        $query = Publisher::whereHas('editions', fn (Builder $editions) => $editions->approved()->whereHas('book', fn (Builder $book) => $book->approved()));
        SearchKeys::match($query, 'publishers.search_key', $terms);
        $total = (clone $query)->count();

        SearchKeys::rank($query, 'publishers.search_key', $terms);
        $publishers = $query->withBookCount(approvedOnly: true)
            ->orderByDesc('books_count')->orderBy('publishers.name')
            ->limit($limit)->get();

        return [
            'items' => $publishers->map(fn (Publisher $publisher) => [
                'slug' => $publisher->slug,
                'name' => $publisher->name,
                'logoUrl' => $publisher->logo_url,
                'bookCount' => (int) $publisher->books_count,
            ])->values(),
            'total' => $total,
        ];
    }
}
