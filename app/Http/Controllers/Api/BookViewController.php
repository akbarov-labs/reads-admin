<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SiteStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A book page opened on the site. The page reports it from the browser
 * once it has loaded, and the site passes it on here having dropped bots by
 * user agent; most crawlers never run the script in the first place.
 */
class BookViewController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'book_id' => ['required', 'integer', 'exists:books,id'],
            'locale' => ['required', Rule::in(['uz', 'ru', 'en'])],
            'ip' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:512'],
        ]);

        $hash = SiteStats::visitorHash($data['ip'] ?? null, $data['user_agent'] ?? null);

        // A reload, or back and forth between two books, is one view.
        if ($hash !== null && ! Cache::add("book-view:{$hash}:{$data['book_id']}", true, now()->addMinutes(SiteStats::REPEAT_MINUTES))) {
            return response()->noContent();
        }

        DB::table('book_views')->upsert(
            [['book_id' => $data['book_id'], 'day' => SiteStats::today(), 'locale' => $data['locale'], 'views' => 1]],
            ['book_id', 'day', 'locale'],
            ['views' => DB::raw('book_views.views + 1')],
        );

        return response()->noContent();
    }
}
