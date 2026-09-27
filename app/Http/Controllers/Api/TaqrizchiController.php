<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TaqrizchiResource;
use App\Models\Taqrizchi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaqrizchiController extends Controller
{
    // Keep in sync with Reads/i18n/routing.ts's locales list.
    private const SUPPORTED_LOCALES = ['uz', 'ru', 'en'];

    /**
     * Every eager load of `taqrizlar` here is constrained to approved. The
     * moderation rule is enforced at the query, not at the resource — a
     * resource that forgets to filter leaks; a query that never loads the
     * pending rows cannot.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->applyRequestedLocale($request);

        return TaqrizchiResource::collection(
            Taqrizchi::with(['socialLinks', 'taqrizlar' => fn ($q) => $q->approved()->with(['edition.book.authors', 'edition.publisher', 'edition.translators'])])
                ->orderBy('name')
                ->get(),
        );
    }

    public function show(Request $request, Taqrizchi $taqrizchi): TaqrizchiResource
    {
        $this->applyRequestedLocale($request);

        $taqrizchi->load(['socialLinks', 'taqrizlar' => fn ($q) => $q->approved()->with(['edition.book.authors', 'edition.publisher', 'edition.translators'])]);

        return new TaqrizchiResource($taqrizchi);
    }

    private function applyRequestedLocale(Request $request): void
    {
        $locale = $request->query('locale');

        if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($locale);
        }
    }
}
