<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SearchQuery;
use App\Support\SiteStats;
use App\Support\UzbekText;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A search on the site: the books listing for a query, or a suggestion the
 * reader picked from the search box. Keystrokes are never sent, only what a
 * reader settled on.
 */
class SearchQueryController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:500'],
            'results' => ['nullable', 'integer', 'min:0'],
            'source' => ['required', Rule::in([SearchQuery::SOURCE_PAGE, SearchQuery::SOURCE_BOX])],
            // A page on the site: /book/12, /author/tolstoy.
            'picked' => ['nullable', 'string', 'max:200', 'regex:#^/[a-z-]+/[\pL\pN._%-]+$#u'],
            'locale' => ['nullable', Rule::in(['uz', 'ru', 'en'])],
            'ip' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:512'],
        ]);

        $query = Str::limit((string) UzbekText::clean($data['query']), 100, '');
        $key = UzbekText::searchKey($query);

        if ($key === null) {
            return response()->noContent();
        }

        $key = Str::limit($key, 100, '');
        $hash = SiteStats::visitorHash($data['ip'] ?? null, $data['user_agent'] ?? null);

        // The same words from the same visitor again (back, reload, the next
        // page of results) are one search.
        if ($hash !== null && ! Cache::add("search:{$hash}:{$data['source']}:".md5($key), true, now()->addMinutes(SiteStats::REPEAT_MINUTES))) {
            return response()->noContent();
        }

        SearchQuery::create([
            'query' => $query,
            'query_key' => $key,
            'results' => $data['source'] === SearchQuery::SOURCE_PAGE ? ($data['results'] ?? null) : null,
            'source' => $data['source'],
            'picked' => $data['source'] === SearchQuery::SOURCE_BOX ? ($data['picked'] ?? null) : null,
            'locale' => $data['locale'] ?? null,
            'visitor_hash' => $hash,
        ]);

        return response()->noContent();
    }
}
