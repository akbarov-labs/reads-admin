<?php

namespace App\Models;

use App\Models\Concerns\HasSearchKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A name and slug an author went by before being merged into this one. */
#[Fillable(['author_id', 'name', 'slug'])]
class AuthorAlias extends Model
{
    use HasSearchKey;

    /** @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
