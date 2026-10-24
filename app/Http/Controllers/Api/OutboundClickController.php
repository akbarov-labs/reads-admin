<?php

namespace App\Http\Controllers\Api;

use App\Enums\OutboundClickKind;
use App\Http\Controllers\Controller;
use App\Models\OutboundClick;
use App\Support\SiteStats;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * The site's click log. The site's /go/... redirect posts here after it has
 * already sent the reader on, so nothing a reader waits for depends on it.
 * The site has dropped bots by user agent before calling.
 */
class OutboundClickController extends Controller
{
    /** Clicks one visitor can add in a minute; past that it is someone inflating a count. */
    private const PER_VISITOR_PER_MINUTE = 30;

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(OutboundClickKind::class)],
            'book_id' => ['nullable', 'integer', 'exists:books,id'],
            'target' => ['required', 'string', 'regex:/^[a-z0-9.-]{1,64}$/'],
            'locale' => ['nullable', Rule::in(['uz', 'ru', 'en'])],
            // The visitor's, as the site saw them. Hashed below, never stored.
            'ip' => ['nullable', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:512'],
        ]);

        $hash = SiteStats::visitorHash($data['ip'] ?? null, $data['user_agent'] ?? null);

        // Accepted either way: the site does nothing with the answer, and a
        // script hammering one link learns nothing from it.
        if ($hash !== null && RateLimiter::tooManyAttempts('outbound-click:'.$hash, self::PER_VISITOR_PER_MINUTE)) {
            return response()->noContent();
        }

        if ($hash !== null) {
            RateLimiter::hit('outbound-click:'.$hash, 60);
        }

        OutboundClick::create([
            'kind' => $data['kind'],
            'book_id' => $data['book_id'] ?? null,
            'target' => $data['target'],
            'locale' => $data['locale'] ?? null,
            'visitor_hash' => $hash,
        ]);

        return response()->noContent();
    }
}
