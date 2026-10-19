<?php

use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\CatalogueSyncController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\PublisherController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\TaqrizchiController;
use App\Http\Controllers\Api\TaqrizController;
use App\Http\Controllers\Api\TranslatorController;
use App\Http\Middleware\EnsureCatalogueSyncToken;
use Illuminate\Support\Facades\Route;

// Read endpoints are public, but not unlimited: in normal operation only the
// Next.js site calls them, a handful of times per ISR window. A ceiling this
// far above that costs legitimate traffic nothing while taking scraping and
// cheap floods off the table.
Route::middleware('throttle:120,1')->group(function () {
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
    // Ids, slugs and dates for the site's sitemap, in one response.
    Route::get('/sitemap', [SitemapController::class, 'index']);
});

// Public inquiry form submissions. Rate-limited per IP on top of the
// honeypot check in StoreInquiryRequest/InquiryController.
Route::post('/translators/{translator}/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:5,1');

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
