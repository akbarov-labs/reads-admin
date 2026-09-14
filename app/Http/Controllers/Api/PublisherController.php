<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PublisherResource;
use App\Models\Publisher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublisherController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        return PublisherResource::collection(Publisher::withCount('books')->orderBy('name')->get());
    }

    public function show(Request $request, Publisher $publisher): PublisherResource
    {
        $this->applyRequestedLocale($request);
        $publisher->loadCount('books');

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