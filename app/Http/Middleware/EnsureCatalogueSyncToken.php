<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/sync/*, which writes to the catalogue and so cannot sit in
 * the public read group. Shared secrets rather than Sanctum: the callers
 * are Telegram bots, not users of this app. Each bot has its own token —
 * the Kitoblarim bot's `sync_token` and the Kitob Marafoni bot's
 * `marathon_token` (config/catalogue.php) — so one can be rotated or
 * switched off without touching the other.
 *
 * With neither token set this is a "not configured" 503, never an open
 * door: with no secret to compare against, "any request is authorised" is
 * the failure mode to rule out. An unset token matches nothing.
 */
class EnsureCatalogueSyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::tokens() === []) {
            return response()->json(['message' => 'Catalogue sync is not configured'], 503);
        }

        if (self::caller($request) === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }

    /**
     * Which bot sent the request, by its token: `kitoblarim`, `marathon`,
     * or null for a missing or wrong one. The rate limiter asks too
     * (AppServiceProvider), so each bot gets its own bucket.
     */
    public static function caller(Request $request): ?string
    {
        $given = (string) $request->bearerToken();

        if ($given === '') {
            return null;
        }

        $caller = null;

        // hash_equals so the response time does not leak how much of the
        // token was right; every configured token is compared either way.
        foreach (self::tokens() as $name => $expected) {
            if (hash_equals($expected, $given)) {
                $caller ??= $name;
            }
        }

        return $caller;
    }

    /** @return array<string, string> caller => token, the configured ones only */
    private static function tokens(): array
    {
        return array_filter([
            'kitoblarim' => (string) config('catalogue.sync_token'),
            'marathon' => (string) config('catalogue.marathon_token'),
        ], fn (string $token) => $token !== '');
    }
}
