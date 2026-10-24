<?php

namespace App\Models;

use App\Support\SiteStats;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One search on the site: on the books listing, or a suggestion picked from
 * the search box as the reader typed. See the create_book_views_and_
 * search_queries_tables migration for the columns.
 */
#[Fillable([
    'query',
    'query_key',
    'results',
    'source',
    'picked',
    'locale',
    'visitor_hash',
    'created_at',
])]
class SearchQuery extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_PAGE = 'page';

    public const SOURCE_BOX = 'box';

    protected function casts(): array
    {
        return [
            'results' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeBetweenDays(Builder $query, ?string $from, ?string $until): void
    {
        SiteStats::betweenDays($query, $from, $until);
    }
}
