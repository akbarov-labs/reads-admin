<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many times a book's page was opened on one Tashkent day in one site
 * language. A counter, not a row per view (see the migration); a visitor
 * reloading the page within SiteStats::REPEAT_MINUTES is not counted again.
 */
#[Fillable(['book_id', 'day', 'locale', 'views'])]
class BookView extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['views' => 'integer'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Counters for the days from one to another, either end left open. The
     * day column is already a Tashkent date.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBetweenDays(Builder $query, ?string $from, ?string $until): void
    {
        $query->when($from, fn (Builder $query) => $query->where('day', '>=', $from))
            ->when($until, fn (Builder $query) => $query->where('day', '<=', $until));
    }
}
