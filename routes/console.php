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
