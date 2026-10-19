<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookResource;
use App\Http\Resources\Api\CollectionResource;
use App\Models\Book;
use App\Models\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Editors' collections of books around translation, published ones only. */
class CollectionController extends Controller
{
    /** How many covers a collection's card shows. */
    private const COVERS = 4;

    /** Every published collection in the editors' order, each with its book count and first covers. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        $collections = Collection::published()
            ->withCount(['items' => fn (Builder $items) => $items->whereHas('book', fn (Builder $book) => $book->approved())])
            ->with(['items' => fn ($items) => $items->whereHas('book', fn (Builder $book) => $book->approved())->with('book.approvedEditions')])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($collections as $collection) {
            $collection->setAttribute('cover_urls', $collection->items
                ->map(fn ($item) => $item->book->primaryEdition?->cover_url)
                ->filter()
                ->take(self::COVERS)
                ->values()
                ->all());
            $collection->unsetRelation('items');
        }

        return CollectionResource::collection($collections);
    }

    /** One published collection with its published books, in order. */
    public function show(Request $request, Collection $collection): CollectionResource
    {
        if (! $collection->is_published) {
            throw new NotFoundHttpException;
        }

        $this->applyRequestedLocale($request);

        $collection->load(['items' => fn ($items) => $items
            ->whereHas('book', fn (Builder $book) => $book->approved())
            ->with([
                'book' => fn ($book) => $book->with(BookResource::relations()),
                'edition' => fn ($edition) => $edition->with(['publisher', 'translators']),
            ]),
        ]);

        return new CollectionResource($collection);
    }

    private function applyRequestedLocale(Request $request): void
    {
        $locale = $request->query('locale');
        if (in_array($locale, ['uz', 'ru', 'en'], true)) {
            app()->setLocale($locale);
        }
    }
}
