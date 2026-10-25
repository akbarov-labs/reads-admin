<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a library's list and the book it was matched to.
 *
 * `match` says how: by ISBN or by exact title (and author), which are
 * accepted straight away; "suggested" when the title fits more than one
 * book or the author does not fit, which waits for the owner; "none".
 */
#[Fillable([
    'library_import_id',
    'row_number',
    'title',
    'author',
    'isbn',
    'copies',
    'available',
    'match',
    'candidate_ids',
    'book_id',
    'edition_id',
    'decision',
])]
class LibraryImportRow extends Model
{
    public const MATCH_ISBN = 'isbn';

    public const MATCH_EXACT = 'exact';

    public const MATCH_SUGGESTED = 'suggested';

    public const MATCH_MANUAL = 'manual';

    public const MATCH_NONE = 'none';

    public const PENDING = 'pending';

    public const ACCEPT = 'accept';

    public const SKIP = 'skip';

    protected function casts(): array
    {
        return [
            'candidate_ids' => 'array',
            'copies' => 'integer',
            'available' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(LibraryImport::class, 'library_import_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }
}
