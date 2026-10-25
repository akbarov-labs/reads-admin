<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A name and slug a publisher went by before being merged into this one. */
#[Fillable(['publisher_id', 'name', 'slug'])]
class PublisherAlias extends Model
{
    /** @return BelongsTo<Publisher, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }
}
