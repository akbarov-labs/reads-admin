<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applies to the panel, the API and anything else served by PHP.
        // Files under /storage are served by nginx directly and get their
        // own headers there (docker/prod/nginx.conf).
        $middleware->append(SecurityHeaders::class);

        // Caddy terminates TLS and forwards plain HTTP into this container
        // over the internal Docker network. Without trusting it, Laravel
        // sees an http request and builds http:// asset URLs, which the
        // browser then blocks as mixed content on the https page.
        //
        // Trusting any proxy is safe here specifically because the container
        // publishes no ports (see docker-compose.prod.yml) -- nothing outside
        // the Docker network can reach it to spoof X-Forwarded-* headers.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
