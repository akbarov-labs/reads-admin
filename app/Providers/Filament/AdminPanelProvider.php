<?php

namespace App\Providers\Filament;

use App\Filament\Stats;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // The catalogue is part of Kitoblarim (the Telegram bot), under its
            // name, mark and Deep Forest Green.
            ->brandName('Kitoblarim katalogi')
            ->favicon(asset('kitoblarim-icon.svg'))
            ->login()
            ->profile()
            ->colors([
                'primary' => Color::hex('#1F4D2E'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // The Site stats page's widgets. Kept out of Filament/Widgets so
            // the main dashboard does not show them, so registered by hand.
            ->livewireComponents([
                Stats\SiteStatsOverview::class,
                Stats\ViewsAndSearchesChart::class,
                Stats\ClicksPerDayChart::class,
                Stats\TopBooksTable::class,
                Stats\TopStoresTable::class,
                Stats\TopSearchesTable::class,
                Stats\NoResultSearchesTable::class,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => (Auth::user()?->isTranslator() || Auth::user()?->isPublisher())
                    ? new HtmlString(<<<'HTML'
                        <style>
                            .fi-sidebar, .fi-topbar-open-sidebar-btn { display: none !important; }
                        </style>
                        HTML)
                    : '',
            )
            // The sidebar (hidden for translators and publishers, above)
            // normally carries the only logo/home link back to the
            // dashboard — this keeps that path available in the topbar
            // regardless of role.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.render-hooks.dashboard-link'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
