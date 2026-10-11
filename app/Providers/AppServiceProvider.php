<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Translatable\Facades\Translatable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A translator's bio/location/title fall back to Uzbek (this site's
        // default locale) when the requested language wasn't filled in, and
        // to whatever *is* filled in as a last resort rather than blank.
        Translatable::fallback(fallbackLocale: 'uz', fallbackAny: true);

        // The bot's catalogue sync has a bucket of its own. The legacy
        // `throttle:120,1` is keyed by IP alone, so behind the proxy the bot
        // would share it with the public site's reads, and a first backfill
        // (hundreds of pushes) could starve the pages. Token-guarded, so the
        // ceiling can be generous.
        RateLimiter::for('catalogue-sync', fn () => Limit::perMinute(600)->by('catalogue-sync'));
    }
}
