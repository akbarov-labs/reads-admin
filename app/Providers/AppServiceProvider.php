<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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

        // The bot's catalogue sync has a bucket of its own. The public read
        // limit is keyed by IP for strangers, so behind the proxy the bot
        // would share it with the public site's reads, and a first backfill
        // (hundreds of pushes) could starve the pages. Token-guarded, so the
        // ceiling can be generous. The throttle runs before the token check,
        // so a caller without the right token gets a small bucket per IP
        // instead: strangers must not be able to use up the bot's.
        RateLimiter::for('catalogue-sync', function (Request $request) {
            $token = (string) config('catalogue.sync_token');
            $given = (string) $request->bearerToken();

            return $token !== '' && $given !== '' && hash_equals($token, $given)
                ? Limit::perMinute(600)->by('catalogue-sync')
                : Limit::perMinute(30)->by('catalogue-sync-stranger:'.$request->ip());
        });

        // The public read API. Strangers get 120 a minute per IP, but the
        // Next.js site is one IP serving every visitor and every crawler: when
        // Googlebot walks the catalogue's uncached pages, 120 a minute runs
        // out, the API answers 429 and the site renders those pages as 500s
        // (Search Console: "Server error (5xx)"). The site proves itself with
        // the secret the two apps already share for revalidation pings, and
        // gets a bucket of its own sized for a crawl, not for a person.
        RateLimiter::for('public-read', function (Request $request) {
            $secret = (string) config('seo.revalidate.secret');
            $given = (string) $request->bearerToken();

            return $secret !== '' && $given !== '' && hash_equals($secret, $given)
                ? Limit::perMinute(3000)->by('public-read-site')
                : Limit::perMinute(120)->by('public-read:'.$request->ip());
        });
    }
}
