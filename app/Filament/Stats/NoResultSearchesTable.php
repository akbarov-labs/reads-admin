<?php

namespace App\Filament\Stats;

use App\Models\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing searches that found no book: books readers want that the
 * catalogue lacks, or has under a spelling search does not match. Either
 * way, worth adding or fixing.
 */
class NoResultSearchesTable extends SearchesTable
{
    protected static ?string $heading = 'Searches with no results';

    protected function scope(Builder $query): Builder
    {
        return $query->where('source', SearchQuery::SOURCE_PAGE)->where('results', 0);
    }
}
