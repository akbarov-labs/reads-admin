<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The public site (tarjima.*) posts inquiry forms from the visitor's own
| browser straight to this API on dashboard.*, so that origin has to be
| allowed. It used to be "*", which also handed every other site on the
| internet a browser-driven way to spam the inquiry endpoint on a visitor's
| behalf. The read endpoints are public either way — this is about the write.
|
| Set CORS_ALLOWED_ORIGINS (comma-separated) to override, e.g. when the site
| moves to another host or a second front-end is added.
|
*/

$origins = env('CORS_ALLOWED_ORIGINS') ?: implode(',', array_filter([
    env('SEO_SITE_URL', 'https://tarjima.kitoblarim.uz'),
    env('APP_URL'),
]));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter(
        array_map(fn ($origin) => rtrim(trim((string) $origin), '/'), explode(',', (string) $origins)),
    ))),

    // Local development runs the site on whatever host the developer's
    // machine happens to have — localhost, or a LAN IP so a phone on the
    // same Wi-Fi can reach it. Matching those by pattern keeps dev working
    // without having to edit .env every time the router hands out a new
    // address. Never active in production.
    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '#^http://(localhost|127\.0\.0\.1|\[::1\]|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(:\d+)?$#',
    ],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
