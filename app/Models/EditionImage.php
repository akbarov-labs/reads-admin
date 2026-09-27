<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A gallery photo of an edition beyond its cover: back cover, sample pages. */
#[Fillable(['edition_id', 'path', 'position'])]
class EditionImage extends Model
{
    /** @return BelongsTo<Edition, $this> */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function getUrlAttribute(): string
    {
        return '/storage/'.$this->path;
    }
}
