<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/sync/*, which writes to the catalogue and so cannot sit in
 * the public read group. One shared secret rather than Sanctum: the only
 * caller is the Kitoblarim bot, and it is not a user of this app.
 *
 * An unset token is a "not configured" 503, never an open door: with no
 * secret to compare against, "any request is authorised" is the failure
 * mode to rule out.
 */
class EnsureCatalogueSyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('catalogue.sync_token');

        if ($expected === '') {
            return response()->json(['message' => 'Catalogue sync is not configured'], 503);
        }

        $given = (string) $request->bearerToken();

        // hash_equals so the response time does not leak how much of the
        // token was right.
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
