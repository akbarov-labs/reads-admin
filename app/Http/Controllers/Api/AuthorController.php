<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AuthorResource;
use App\Models\Author;
use App\Support\CatalogueCovers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        $query = Author::whereHas('books', fn ($books) => $books->approved())
            ->withCount(['books' => fn ($books) => $books->approved()]);

        if (filled($q = trim((string) $request->query('q')))) {
            $query->where(fn ($match) => $match->where('name', 'like', "%{$q}%")->orWhere('original_name', 'like', "%{$q}%"));
        }

        $request->query('sort') === 'name'
            ? $query->orderBy('name')
            : $query->orderByDesc('books_count')->orderBy('name');

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