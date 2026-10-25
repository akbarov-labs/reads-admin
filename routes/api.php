<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BookViewController;
use App\Http\Controllers\Api\CatalogueSyncController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\OutboundClickController;
use App\Http\Controllers\Api\PublisherController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SearchQueryController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\TaqrizchiController;
use App\Http\Controllers\Api\TaqrizController;
use App\Http\Controllers\Api\TranslatorController;
use App\Http\Middleware\EnsureCatalogueSyncToken;
use App\Http\Middleware\EnsureSiteToken;
use Illuminate\Support\Facades\Route;

// Read endpoints are public, but not unlimited: 120 a minute per IP takes
// scraping and cheap floods off the table. The Next.js site, which sends
// every visitor's and crawler's cache misses from one IP, has a bucket of its
// own (`public-read` in AppServiceProvider).
Route::middleware('throttle:public-read')->group(function () {
    Route::get('/translators', [TranslatorController::class, 'index']);
    Route::get('/translators/{translator}', [TranslatorController::class, 'show']);
    Route::get('/authors', [AuthorController::class, 'index']);
    Route::get('/authors/{author}', [AuthorController::class, 'show']);
    Route::get('/books', [BookController::class, 'index']);
    Route::get('/books/{book}', [BookController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/publishers', [PublisherController::class, 'index']);
    Route::get('/publishers/{publisher}', [PublisherController::class, 'show']);
    Route::get('/taqrizchilar', [TaqrizchiController::class, 'index']);
    Route::get('/taqrizchilar/{taqrizchi}', [TaqrizchiController::class, 'show']);
    Route::get('/taqrizlar/{taqriz}', [TaqrizController::class, 'show']);
    Route::get('/collections', [CollectionController::class, 'index']);
    Route::get('/collections/{collection}', [CollectionController::class, 'show']);
    Route::get('/quotes/today', [QuoteController::class, 'today']);
    Route::get('/libraries', [LibraryController::class, 'index']);
    Route::get('/libraries/{library:slug}', [LibraryController::class, 'show']);
    Route::get('/libraries/{library:slug}/books', [LibraryController::class, 'books']);
    // Publishers' new books and pre-orders on the site today.
    Route::get('/announcements', [AnnouncementController::class, 'index']);
    Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
    // The search box's grouped suggestions. The site forwards each
    // visitor's address and sends no token, so every visitor has a
    // stranger's 120 a minute and nobody's typing eats the site's bucket.
    Route::get('/search', SearchController::class);
    // Ids, slugs and dates for the site's sitemap, in one response.
    Route::get('/sitemap', [SitemapController::class, 'index']);
});

// Public inquiry form submissions. Rate-limited per IP on top of the
// honeypot check in StoreInquiryRequest/InquiryController.
Route::post('/translators/{translator}/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:5,1');

// The site's own statistics: a reader it sent on to a store, a book page
// opened, a search. Only the site writes here, with the secret it already
// sends on its reads.
Route::middleware(['throttle:site-writes', EnsureSiteToken::class])->group(function () {
    Route::post('/clicks', [OutboundClickController::class, 'store']);
    Route::post('/views', [BookViewController::class, 'store']);
    Route::post('/searches', [SearchQueryController::class, 'store']);
});

// The Kitoblarim bot's catalogue sync. Unlike everything above it writes, so
// it is behind a shared token and deliberately not part of the public read
// group. Its own rate-limit bucket (AppServiceProvider): the bot's first
// backfill pages through the feed at 500 a request and pushes one edition at
// a time.
Route::prefix('sync')->middleware(['throttle:catalogue-sync', EnsureCatalogueSyncToken::class])->group(function () {
    Route::get('/editions', [CatalogueSyncController::class, 'index']);
    Route::put('/editions/{isbn}', [CatalogueSyncController::class, 'upsert']);
    Route::put('/ratings', [CatalogueSyncController::class, 'ratings']);
    Route::put('/readers', [CatalogueSyncController::class, 'readers']);
    Route::put('/reviews', [CatalogueSyncController::class, 'reviews']);
});
