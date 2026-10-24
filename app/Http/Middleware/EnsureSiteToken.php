<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints only the public Next.js site may write to, such as
 * its click log. The site already holds the secret the two apps share for
 * revalidation pings (seo.revalidate.secret) and sends it on every API read,
 * so it is the same secret here rather than a new one to deploy.
 *
 * As in EnsureCatalogueSyncToken, an unset secret is a 503, never an open
 * door.
 */
class EnsureSiteToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('seo.revalidate.secret');

        if ($expected === '') {
            return response()->json(['message' => 'The site token is not configured'], 503);
        }

        $given = (string) $request->bearerToken();

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
