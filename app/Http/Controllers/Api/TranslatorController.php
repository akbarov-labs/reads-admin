<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TranslatorResource;
use App\Models\Translator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TranslatorController extends Controller
{
    // Keep in sync with Reads/i18n/routing.ts's locales list.
    private const SUPPORTED_LOCALES = ['uz', 'ru', 'en'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        $translators = Translator::with(['languagePairs', 'books.authorRecord', 'books.publisherRecord', 'socialLinks'])->get();

        return TranslatorResource::collection($translators);
    }

    public function show(Request $request, Translator $translator): TranslatorResource
    {
        $this->applyRequestedLocale($request);

        $translator->load(['languagePairs', 'books.authorRecord', 'books.publisherRecord', 'socialLinks']);

        return new TranslatorResource($translator);
    }

    /**
     * Resolves translated fields (bio, location, title) to the visitor's
     * chosen site language via ?locale=. Falls back to Uzbek (configured in
     * AppServiceProvider) when a translator hasn't filled in that language.
     */
    private function applyRequestedLocale(Request $request): void
    {
        $locale = $request->query('locale');

        if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($locale);
        }
    }
}
