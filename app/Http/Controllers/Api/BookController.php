<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CatalogBookResource;
use App\Models\Book;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CatalogBookResource::collection(
            Book::with(['authorRecord', 'publisherRecord', 'translator'])->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function show(Book $book): CatalogBookResource
    {
        $book->load(['authorRecord', 'publisherRecord', 'translator']);

        return new CatalogBookResource($book);
    }
}