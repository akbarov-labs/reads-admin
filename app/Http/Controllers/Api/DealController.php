<?php

namespace App\Http\Controllers\Api;

use App\Enums\DealKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DealResource;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Discounts, contests and events on the site today (Deal::live): the Chegirmalar
 * page, the home page's few, a publisher's own, and the button's redirect.
 *
 *   ?kind=       discount | contest | event
 *   ?publisher=  a publisher's slug
 *   ?limit=      at most 48
 */
class DealController extends Controller
{
    private const MAX_LIMIT = 48;

    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query('limit', 24)));
        $kind = DealKind::tryFrom((string) $request->query('kind'));

        $deals = Deal::live()
            ->with(['publisher', 'book'])
            ->when($kind, fn (Builder $query, DealKind $kind) => $query->where('kind', $kind))
            ->when($request->query('publisher'), fn (Builder $query, string $slug) => $query->whereHas('publisher', fn (Builder $query) => $query->where('slug', $slug)))
            // Ending soonest first, so a reader doesn't miss one; then the
            // newest of those that name no end.
            ->orderByRaw('ends_on is null')
            ->orderBy('ends_on')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return DealResource::collection($deals);
    }

    public function show(string $deal): DealResource
    {
        // Ended or unpublished is gone from here too.
        $found = ctype_digit($deal) ? Deal::live()->with(['publisher', 'book'])->find($deal) : null;

        if ($found === null) {
            throw new NotFoundHttpException;
        }

        return new DealResource($found);
    }
}
