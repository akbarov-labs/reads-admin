<?php

use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\AuthorController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\PublisherController;
use App\Http\Controllers\Api\TaqrizchiController;
use App\Http\Controllers\Api\TaqrizController;
use App\Http\Controllers\Api\TranslatorController;
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
    Route::get('/publishers', [PublisherController::class, 'index']);
    Route::get('/publishers/{publisher}', [PublisherController::class, 'show']);
    Route::get('/taqrizchilar', [TaqrizchiController::class, 'index']);
    Route::get('/taqrizchilar/{taqrizchi}', [TaqrizchiController::class, 'show']);
    Route::get('/taqrizlar/{taqriz}', [TaqrizController::class, 'show']);
});

// Public inquiry form submissions. Rate-limited per IP on top of the
// honeypot check in StoreInquiryRequest/InquiryController.
Route::post('/translators/{translator}/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:5,1');
