<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Softly, the library system behind kutubxonam.uz. We were allowed to
     * read its API on the condition that we do not ask too much of it, so
     * every request goes through SoftlyClient, which spaces them out, stops
     * at a daily cap, and stops for a while on any error.
     *
     * Off unless SOFTLY_SYNC_ENABLED is set: production only, so staging
     * does not double the load.
     */
    'softly' => [
        'enabled' => (bool) env('SOFTLY_SYNC_ENABLED', false),
        'url' => env('SOFTLY_URL', 'https://library.softly.uz/api/app'),
        'page_size' => (int) env('SOFTLY_PAGE_SIZE', 100),
        'delay_ms' => (int) env('SOFTLY_DELAY_MS', 2000),
        'daily_cap' => (int) env('SOFTLY_DAILY_CAP', 300),
        'pause_minutes' => (int) env('SOFTLY_PAUSE_MINUTES', 60),
        // "changed" reads only what changed since the last sync; "full"
        // reads everything, for when a copy going out turns out not to
        // move its book's updatedAt (the nightly log counts those).
        'quick_mode' => env('SOFTLY_QUICK_MODE', 'changed'),
        // So Softly can see who is calling and how to reach us.
        'user_agent' => env('SOFTLY_USER_AGENT', 'KitoblarimReads/1.0 (+https://kitoblarim.uz)'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
