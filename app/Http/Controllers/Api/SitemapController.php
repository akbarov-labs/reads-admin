<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Collection;
use App\Models\Library;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Everything the public site's sitemap lists, in one response: every
 * approved book, and every author and publisher with an approved book, each
 * with when it last changed. The site would otherwise page through
 * /api/books 100 at a time (130-odd requests for the catalogue), which the
 * read throttle rightly refuses. Ids, slugs and dates only, so it stays
 * small and fast.
 */
class SitemapController extends Controller
{
    public function index(): JsonResponse
    {
        $books = Book::approved()
            ->orderBy('books.id')
            ->get(['books.id', 'books.updated_at'])
            ->map(fn (Book $book) => ['id' => (string) $book->id, 'updatedAt' => $book->updated_at?->toIso8601String()]);

        $authors = DB::table('authors')
            ->join('book_author', 'book_author.author_id', '=', 'authors.id')
            ->whereIn('book_author.book_id', Book::approved()->select('books.id'))
            ->groupBy('authors.id', 'authors.slug', 'authors.updated_at')
            ->orderBy('authors.id')
            ->get(['authors.slug', 'authors.updated_at'])
            ->map(fn ($author) => ['slug' => $author->slug, 'updatedAt' => $this->iso($author->updated_at)]);

        // Joined rather than whereHas: a correlated subquery per row is what
        // made /api/authors take 30 s on SQLite (AuthorController::index).
        $publishers = DB::table('publishers')
            ->join('editions', 'editions.publisher_id', '=', 'publishers.id')
            ->where('editions.status', ModerationStatus::Approved->value)
            ->whereIn('editions.book_id', Book::approved()->select('books.id'))
            ->groupBy('publishers.id', 'publishers.slug', 'publishers.updated_at')
            ->orderBy('publishers.id')
            ->get(['publishers.slug', 'publishers.updated_at'])
            ->map(fn ($publisher) => ['slug' => $publisher->slug, 'updatedAt' => $this->iso($publisher->updated_at)]);

        $collections = Collection::published()
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->map(fn (Collection $collection) => ['slug' => $collection->slug, 'updatedAt' => $collection->updated_at?->toIso8601String()]);

        $libraries = Library::published()
            ->orderBy('id')
            ->get(['slug', 'updated_at', 'holdings_updated_at'])
            ->map(fn (Library $library) => [
                'slug' => $library->slug,
                'updatedAt' => collect([$library->updated_at, $library->holdings_updated_at])->filter()->max()?->toIso8601String(),
            ]);

        return response()->json([
            'books' => $books->values(),
            'authors' => $authors->values(),
            'publishers' => $publishers->values(),
            'collections' => $collections->values(),
            'libraries' => $libraries->values(),
        ]);
    }

    private function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
