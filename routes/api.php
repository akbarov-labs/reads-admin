<?php

use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\TranslatorController;
use Illuminate\Support\Facades\Route;

Route::get('/translators', [TranslatorController::class, 'index']);
Route::get('/translators/{translator}', [TranslatorController::class, 'show']);

// Public inquiry form submissions. Rate-limited per IP on top of the
// honeypot check in StoreInquiryRequest/InquiryController.
Route::post('/translators/{translator}/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:5,1');
