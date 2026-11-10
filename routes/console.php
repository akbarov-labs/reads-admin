<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run by `schedule:work` in the app container (docker/prod/supervisord.conf).

// Softly libraries (kutubxonam.uz): what changed, every half hour while
// libraries are open, and everything once a night while they are closed.
// Off unless SOFTLY_SYNC_ENABLED is set; see config/services.php.
Schedule::command('softly:sync')
    ->everyThirtyMinutes()
    ->between('8:00', '21:00')
    ->timezone('Asia/Tashkent')
    ->withoutOverlapping();

Schedule::command('softly:sync --full')
    ->dailyAt('02:30')
    ->timezone('Asia/Tashkent')
    ->withoutOverlapping();

// Publishers' Telegram channels: new posts that look like a new book or a
// pre-order become drafts in Announcements, for an admin to publish. Hourly
// while publishers post; the night's posts are picked up in the morning.
Schedule::command('announcements:scan')
    ->hourly()
    ->between('8:00', '23:00')
    ->timezone('Asia/Tashkent')
    ->withoutOverlapping();

// qamar.uz: book pages new or changed since last night, slowly, then
// catalogue blanks filled by ISBN (StoreListingApplier). Production only,
// so staging doesn't read the shop a second time; QAMAR_FEED_ENABLED=false
// turns it off there too.
Schedule::command('stores:qamar')
    ->dailyAt('03:30')
    ->timezone('Asia/Tashkent')
    ->when(fn () => config('services.qamar.enabled') && app()->environment('production'))
    ->withoutOverlapping();

// asaxiy.uz: price and stock for the editions imported from it, the pages
// read longest ago first. An hour before Qamar, so the two never overlap.
// Production only, like stores:qamar.
Schedule::command('stores:asaxiy')
    ->dailyAt('02:15')
    ->timezone('Asia/Tashkent')
    ->when(fn () => config('services.asaxiy.enabled') && app()->environment('production'))
    ->withoutOverlapping();

// hilolnashr.uz: price and stock for every book in its listing, then the
// ISBNs of books it has not read yet. Before Asaxiy and Qamar, so the
// three never overlap. Production only.
Schedule::command('stores:hilol')
    ->dailyAt('01:15')
    ->timezone('Asia/Tashkent')
    ->when(fn () => config('services.hilol.enabled') && app()->environment('production'))
    ->withoutOverlapping();
