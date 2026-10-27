<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book as Softly lists it, and the catalogue book it was matched to. The
 * copies (`stocks`) say which location holds each one and whether it is
 * out; SoftlySync turns them into library_holdings.
 */
#[Fillable([
    'softly_id',
    'name',
    'author',
    'stocks',
    'softly_updated_at',
    'book_id',
    'match',
    'candidate_ids',
    'matched_on',
])]
class SoftlyBook extends Model
{
    protected function casts(): array
    {
        return [
            'stocks' => 'array',
            'candidate_ids' => 'array',
            'softly_updated_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
