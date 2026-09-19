<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CatalogBookResource;
use App\Models\Book;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookController extends Controller
{
    /**
     * `taqrizlar` is constrained to approved at the query, so a pending
     * review is never loaded and so can never be rendered by mistake.
     *
     * @return array<string, mixed>
     */
    private static function relations(): array
    {
        return [
            'authorRecord',
            'publisherRecord',
            'translator',
            'taqrizlar' => fn ($query) => $query->approved()->with('taqrizchi'),
        ];
    }

    public function index(): AnonymousResourceCollection
    {
        return CatalogBookResource::collection(
            Book::with(self::relations())->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function show(Book $book): CatalogBookResource
    {
        $book->load(self::relations());

        return new CatalogBookResource($book);
    }
}