<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers for every response this app serves.
 *
 * Deliberately no script-src/style-src: Filament runs on Alpine, which needs
 * both 'unsafe-eval' and inline styles, so a CSP covering them would have to
 * allow exactly what it is meant to forbid. The directives that *are* here
 * cost nothing and close real holes — clickjacking, <base> hijacking, plugin
 * embedding — regardless of what the page's scripts do.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'",
        ];

        // Only meaningful over TLS, and asserting it on a plain-HTTP local
        // dev host would pin that host to https in the developer's browser.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            // Never clobber a header a controller set on purpose.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
