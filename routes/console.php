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
