<?php

namespace App\Models;

use App\Observers\CollectionObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One book in a collection: optionally the edition it means, and why it is there. */
#[ObservedBy(CollectionObserver::class)]
#[Fillable(['collection_id', 'book_id', 'edition_id', 'note', 'position'])]
class CollectionItem extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
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
}
