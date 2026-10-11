<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalogue sync with the Kitoblarim bot
    |--------------------------------------------------------------------------
    |
    | The Telegram bot pushes the books its moderators approved to
    | PUT /api/sync/editions/{isbn} and pulls ours from GET /api/sync/editions,
    | both with `Authorization: Bearer <token>`. See docs/DATA-MODEL.md.
    |
    | Leave the token unset to switch the endpoints off: they answer 503
    | rather than letting anyone in. Generate one with `openssl rand -hex 32`.
    |
    */

    'sync_token' => env('CATALOGUE_SYNC_TOKEN'),

];
