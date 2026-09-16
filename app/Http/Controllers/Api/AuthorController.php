<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AuthorResource;
use App\Models\Author;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuthorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        return AuthorResource::collection(Author::withCount('books')->orderBy('name')->get());
    }

    public function show(Request $request, Author $author): AuthorResource
    {
        $this->applyRequestedLocale($request);
        $author->loadCount('books');

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