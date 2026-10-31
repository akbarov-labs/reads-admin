<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalogue sync with the Telegram bots
    |--------------------------------------------------------------------------
    |
    | The Kitoblarim bot pushes the books its moderators approved to
    | PUT /api/sync/editions/{isbn} and pulls ours from GET /api/sync/editions.
    | The Kitob Marafoni bot (book marathons) pushes the books its admins add:
    | those with an ISBN to the same PUT, those without one to
    | POST /api/sync/books, which makes a book awaiting review rather than a
    | published one. Each bot sends its own token as
    | `Authorization: Bearer <token>`. See docs/DATA-MODEL.md.
    |
    | Either token may be left unset to shut that bot out; with neither set
    | the endpoints answer 503 rather than letting anyone in. Generate one
    | with `openssl rand -hex 32`.
    |
    */

    'sync_token' => env('CATALOGUE_SYNC_TOKEN'),

    'marathon_token' => env('CATALOGUE_MARATHON_TOKEN'),

];
