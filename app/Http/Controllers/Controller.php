<?php

namespace App\Http\Controllers;

use App\Support\UzbekText;
use Illuminate\Http\Request;

abstract class Controller
{
    /** ?q= spelt the way the catalogue stores text: o‘tkan, oʻtkan and o`tkan all find o'tkan. */
    protected function searchTerm(Request $request): string
    {
        return UzbekText::normalizeApostrophes(trim((string) $request->query('q')));
    }
}
