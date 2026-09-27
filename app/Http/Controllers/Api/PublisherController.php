<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PublisherResource;
use App\Models\Publisher;
use App\Support\CatalogueCovers;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublisherController extends Controller
{
    /**
     * Publishers with at least one published edition, a page at a time:
     * ?q=, ?sort=books (the default) | name, ?page=, ?per_page= (at most 100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        $query = Publisher::whereHas('editions', fn ($editions) => $editions->approved()->whereHas('book', fn ($book) => $book->approved()))
            ->withBookCount(approvedOnly: true);

        if (filled($q = trim((string) $request->query('q')))) {
            $query->where('name', 'like', "%{$q}%");
        }

        $request->query('sort') === 'name'
            ? $query->orderBy('name')
            : $query->orderByDesc('books_count')->orderBy('name');

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $page = $query->paginate($perPage)->withQueryString();
        $covers = CatalogueCovers::forPublishers($page->getCollection()->modelKeys());
        $page->getCollection()->each(fn (Publisher $publisher) => $publisher->setAttribute('cover_urls', $covers[$publisher->id] ?? []));

        return PublisherResource::collection($page);
    }

    public function show(Request $request, Publisher $publisher): PublisherResource
    {
        $this->applyRequestedLocale($request);

        $counted = Publisher::whereKey($publisher->id)->withBookCount(approvedOnly: true)->first();
        $publisher->setAttribute('books_count', $counted->books_count);
        $publisher->setAttribute('cover_urls', CatalogueCovers::forPublishers([$publisher->id])[$publisher->id] ?? []);

        return new PublisherResource($publisher);
    }

    private function applyRequestedLocale(Request $request): void
    {
        $locale = $request->query('locale');
        if (in_array($locale, ['uz', 'ru', 'en'], true)) {
            app()->setLocale($locale);
        }
    }
}