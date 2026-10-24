<?php

namespace App\Models;

use App\Enums\OutboundClickKind;
use App\Support\SiteStats;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader the site sent to a store (see the create_outbound_clicks_table
 * migration). Written once, never edited, so there is no updated_at.
 */
#[Fillable([
    'kind',
    'book_id',
    'target',
    'locale',
    'visitor_hash',
    'created_at',
])]
class OutboundClick extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => OutboundClickKind::class,
            'created_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Clicks from the start of one Tashkent day to the end of another,
     * either end left open.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBetweenDays(Builder $query, ?string $from, ?string $until): void
    {
        SiteStats::betweenDays($query, $from, $until);
    }
}
