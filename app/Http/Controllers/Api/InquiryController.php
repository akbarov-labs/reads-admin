<?php

namespace App\Http\Controllers\Api;

use App\Enums\InquiryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreInquiryRequest;
use App\Models\Translator;
use Illuminate\Http\JsonResponse;

class InquiryController extends Controller
{
    public function store(StoreInquiryRequest $request, Translator $translator): JsonResponse
    {
        // Silently accept-and-drop honeypot hits: a real form field a human
        // never sees or fills. Returning the same success response (rather
        // than a 4xx) gives a spam script nothing to learn from.
        if ($request->isHoneypotFilled()) {
            return response()->json(['message' => 'Inquiry sent.'], 201);
        }

        $translator->inquiries()->create([
            ...$request->safe()->except('website'),
            'status' => InquiryStatus::New,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Inquiry sent.'], 201);
    }
}
