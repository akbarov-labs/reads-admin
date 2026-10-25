<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookResource;
use App\Http\Resources\Api\LibraryHoldingResource;
use App\Http\Resources\Api\LibraryResource;
use App\Models\Library;
use App\Support\SearchKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Libraries on the site. Only published ones, and only the books of theirs
 * that are on the site too: a holding of a book still in moderation is not
 * counted or listed.
 */
class LibraryController extends Controller
{
    /** Every published library, ?region= and ?district= to narrow, by name. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        $libraries = Library::published()
            ->withCount(['holdings as books_count' => fn (Builder $holdings) => $holdings->whereHas('book', fn (Builder $book) => $book->approved())])
            ->when($request->filled('region'), fn (Builder $query) => $query->where('region', $request->query('region')))
            ->when($request->filled('district'), fn (Builder $query) => $query->where('district', $request->query('district')))
            ->orderBy('name')
            ->get();

        return LibraryResource::collection($libraries);
    }

    public function show(Request $request, Library $library): LibraryResource
    {
        $this->published($library);
        $this->applyRequestedLocale($request);

        $library->loadCount(['holdings as books_count' => fn (Builder $holdings) => $holdings->whereHas('book', fn (Builder $book) => $book->approved())]);

        return new LibraryResource($library);
    }

    /**
     * The library's books a page at a time: ?q= (title or author, either
     * alphabet), ?available=1 for only those with a copy on the shelf,
     * ?page=, ?per_page= (at most 100). Each is a book card with the
     * library's copies.
     */
    public function books(Request $request, Library $library): AnonymousResourceCollection
    {
        $this->published($library);
        $this->applyRequestedLocale($request);

        $query = $library->holdings()
            ->whereHas('book', function (Builder $book) use ($request) {
                $book->approved();

                if ($terms = SearchKeys::terms($this->searchTerm($request))) {
                    $book->where(function (Builder $match) use ($terms) {
                        $match->where(fn (Builder $title) => SearchKeys::match($title, 'books.search_key', $terms))
                            ->orWhereHas('authors', fn (Builder $author) => SearchKeys::match($author, 'authors.search_key', $terms));
                    });
                }
            })
            ->when($request->boolean('available'), fn (Builder $holdings) => $holdings->where('copies_available', '>', 0))
            ->join('books', 'books.id', '=', 'library_holdings.book_id')
            ->orderBy('books.uzbek_title')
            ->select('library_holdings.*')
            ->with(['book' => fn ($book) => $book->with(BookResource::relations())]);

        return LibraryHoldingResource::collection(
            $query->paginate(min(100, max(1, (int) $request->query('per_page', 24))))->withQueryString(),
        );
    }

    private function published(Library $library): void
    {
        if (! $library->is_published) {
            throw new NotFoundHttpException;
        }
    }

    private function applyRequestedLocale(Request $request): void
    {
        $locale = $request->query('locale');
        if (in_array($locale, ['uz', 'ru', 'en'], true)) {
            app()->setLocale($locale);
        }
    }
}
