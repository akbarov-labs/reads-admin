<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Observers\QuoteObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line from a book, entered by an editor. Published when saved; an editor
 * can hide one again without deleting it. A quote from a translation names
 * its edition, whose translators then sign it with the author.
 */
#[ObservedBy(QuoteObserver::class)]
#[Fillable(['book_id', 'edition_id', 'body', 'page', 'status'])]
class Quote extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationStatus::Approved->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'page' => 'integer',
        ];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return BelongsTo<Edition, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('quotes.status', ModerationStatus::Approved);
    }

    /**
     * The quote of the day: every published quote of a published book takes
     * its turn, one a day, in the order they were added. The same all day
     * for everyone, and no table to keep.
     */
    public static function ofTheDay(?\DateTimeInterface $day = null): ?self
    {
        $shown = self::approved()->whereHas('book', fn (Builder $book) => $book->approved());
        $count = $shown->count();

        if ($count === 0) {
            return null;
        }

        // Days since 1970 on the app's own clock, so the quote turns at its midnight.
        $at = $day ?? now();
        $dayNumber = intdiv($at->getTimestamp() + $at->getOffset(), 86400);

        return $shown->orderBy('quotes.id')->skip($dayNumber % $count)->first();
    }
}
