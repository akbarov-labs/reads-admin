<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A book that was merged into this one (BookMerger): its old id, so the
 * old page address still arrives; its title key, so it is not made again;
 * and a snapshot of what the merge moved, for `books:unmerge`.
 */
#[Fillable(['merged_book_id', 'book_id', 'merged_title', 'merge_key', 'snapshot'])]
class BookMerge extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** The book an old id now lives on, or null when it was never merged. */
    public static function keptIdFor(int $mergedBookId): ?int
    {
        $id = static::where('merged_book_id', $mergedBookId)->value('book_id');

        return $id === null ? null : (int) $id;
    }
}
