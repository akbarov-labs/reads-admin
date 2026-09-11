<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public site identity
    |--------------------------------------------------------------------------
    |
    | This panel is the admin/API host (dashboard.*). The values below describe
    | the *public* site (tarjima.*) that renders the profiles, because that is
    | the host whose <title>/<meta> tags these defaults end up in.
    |
    */

    'site_name' => env('SEO_SITE_NAME', 'Reads'),

    'site_url' => rtrim((string) env('SEO_SITE_URL', 'https://tarjima.kitoblarim.uz'), '/'),

    /*
    |--------------------------------------------------------------------------
    | On-demand revalidation
    |--------------------------------------------------------------------------
    |
    | The public site caches API responses (ISR). Without a nudge, a new book
    | can sit invisible — and absent from the sitemap — for the length of that
    | cache window. Every write to a translator, book, language pair or social
    | link pings this endpoint so the affected pages are rebuilt immediately.
    |
    | Leave SEO_REVALIDATE_URL unset to turn the pings off (local dev).
    |
    */

    'revalidate' => [
        'url' => env('SEO_REVALIDATE_URL'),
        'secret' => env('SEO_REVALIDATE_SECRET'),
        'timeout' => (int) env('SEO_REVALIDATE_TIMEOUT', 5),
    ],

];
