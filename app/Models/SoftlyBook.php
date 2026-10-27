<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book as one Softly library lists it, and the catalogue book it was matched to. The
 * copies (`stocks`) say which location holds each one and whether it is
 * out; SoftlySync turns them into library_holdings.
 */
#[Fillable([
    'softly_library',
    'softly_id',
    'name',
    'author',
    'stocks',
    'softly_updated_at',
    'book_id',
    'match',
    'candidate_ids',
    'matched_on',
    'skipped',
])]
class SoftlyBook extends Model
{
    /** Its book was made from it: no catalogue book had the title (SoftlyNewBooks). */
    public const MATCH_CREATED = 'created';

    /** The kutubxonam.uz libraries, by Softly library id. */
    public const LIBRARIES = [
        1 => 'Mehr',
        16 => 'Kitobqand',
        22 => 'Izlash',
        25 => 'UzKutubxona.de',
        26 => 'Idrok',
    ];

    protected function casts(): array
    {
        return [
            'softly_library' => 'integer',
            'stocks' => 'array',
            'candidate_ids' => 'array',
            'softly_updated_at' => 'datetime',
            'skipped' => 'boolean',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** Copies across every location. */
    public function copies(): int
    {
        return count($this->stocks ?? []);
    }
}
