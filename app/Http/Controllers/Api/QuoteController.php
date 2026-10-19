<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\QuoteResource;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    /** The home page's quote of the day (Quote::ofTheDay), or `data: null` when there are none. */
    public function today(): JsonResponse|QuoteResource
    {
        $quote = Quote::ofTheDay();

        if ($quote === null) {
            return response()->json(['data' => null]);
        }

        $quote->load(['book.authors', 'book.approvedEditions', 'edition.translators']);

        return new QuoteResource($quote);
    }
}
