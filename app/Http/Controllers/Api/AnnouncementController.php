<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishers' new books and pre-orders that are on the site today
 * (Announcement::live): the home page's section, a publisher's own, and the
 * order button's redirect.
 */
class AnnouncementController extends Controller
{
    private const MAX_LIMIT = 24;

    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query('limit', 12)));

        $announcements = Announcement::live()
            ->with('publisher')
            ->when($request->query('publisher'), fn (Builder $query, string $slug) => $query->whereHas('publisher', fn (Builder $query) => $query->where('slug', $slug)))
            // Pre-orders closing soonest first, then the newest books.
            ->orderByRaw('preorder_deadline is null')
            ->orderBy('preorder_deadline')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return AnnouncementResource::collection($announcements);
    }

    public function show(string $announcement): AnnouncementResource
    {
        // Gone from the site (expired, unpublished) is gone from here too.
        $found = ctype_digit($announcement) ? Announcement::live()->with('publisher')->find($announcement) : null;

        if ($found === null) {
            throw new NotFoundHttpException;
        }

        return new AnnouncementResource($found);
    }
}
