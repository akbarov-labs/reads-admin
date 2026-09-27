<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AuthorResource;
use App\Models\Author;
use App\Models\Book;
use App\Support\CatalogueCovers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AuthorController extends Controller
{
    /**
     * Authors with at least one published book, a page at a time:
     * ?q= (name, either spelling), ?sort=books (most books first, the
     * default) | name, ?page=, ?per_page= (at most 100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        // Each author's published-book count, worked out once for everyone
        // and joined in; the inner join also drops authors with none. The
        // obvious whereHas('books') + withCount('books') are correlated per
        // author, and SQLite plans them by walking every approved book for
        // each of ~4,000 authors — 30 s and a 502 in production.
        $counts = DB::table('book_author')
            ->select('book_author.author_id', DB::raw('count(*) as books_count'))
            ->whereIn('book_author.book_id', Book::approved()->select('books.id'))
            ->groupBy('book_author.author_id');

        $query = Author::query()
            ->joinSub($counts, 'published', 'published.author_id', '=', 'authors.id')
            ->select('authors.*', 'published.books_count');

        if (filled($q = trim((string) $request->query('q')))) {
            $query->where(fn ($match) => $match->where('authors.name', 'like', "%{$q}%")->orWhere('authors.original_name', 'like', "%{$q}%"));
        }

        $request->query('sort') === 'name'
            ? $query->orderBy('authors.name')
            : $query->orderByDesc('published.books_count')->orderBy('authors.name');

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $page = $query->paginate($perPage)->withQueryString();
        $covers = CatalogueCovers::forAuthors($page->getCollection()->modelKeys());
        $page->getCollection()->each(fn (Author $author) => $author->setAttribute('cover_urls', $covers[$author->id] ?? []));

        return AuthorResource::collection($page);
    }

    public function show(Request $request, Author $author): AuthorResource
    {
        $this->applyRequestedLocale($request);
        $author->loadCount(['books' => fn ($query) => $query->approved()]);
        $author->setAttribute('cover_urls', CatalogueCovers::forAuthors([$author->id])[$author->id] ?? []);

        return new AuthorResource($author);
    }

    private function applyRequestedLocale(Request $request): void
    {
        $locale = $request->query('locale');
        if (in_array($locale, ['uz', 'ru', 'en'], true)) {
            app()->setLocale($locale);
        }
    }
}