<?php

namespace App\Providers;

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
    }
}
