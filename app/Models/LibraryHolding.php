<?php

namespace App\Models;

use App\Observers\LibraryHoldingObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One book a library holds: how many copies, and how many are on the shelf
 * right now. No borrower is ever recorded, only the count.
 */
#[ObservedBy(LibraryHoldingObserver::class)]
#[Fillable([
    'library_id',
    'book_id',
    'edition_id',
    'copies_total',
    'copies_available',
    'note',
])]
class LibraryHolding extends Model
{
    protected $attributes = [
        'copies_total' => 1,
        'copies_available' => 1,
    ];

    protected function casts(): array
    {
        return [
            'copies_total' => 'integer',
            'copies_available' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Never more on the shelf than the library owns, never fewer than none.
        static::saving(function (LibraryHolding $holding) {
            $holding->copies_total = max(1, (int) $holding->copies_total);
            $holding->copies_available = min(max(0, (int) $holding->copies_available), $holding->copies_total);
        });
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    /** A copy went home with a reader. */
    public function lend(): void
    {
        if ($this->copies_available > 0) {
            $this->update(['copies_available' => $this->copies_available - 1]);
        }
    }

    /** A copy came back. */
    public function giveBack(): void
    {
        if ($this->copies_available < $this->copies_total) {
            $this->update(['copies_available' => $this->copies_available + 1]);
        }
    }
}
