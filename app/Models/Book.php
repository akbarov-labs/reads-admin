<?php

namespace App\Models;

use App\Enums\BookRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'translator_id',
    'role',
    'uzbek_title',
    'original_title',
    'author',
    'source_language',
    'publisher',
    'year',
    'cover_path',
    'excerpt_source_language',
    'excerpt_source_text',
    'excerpt_translated_text',
    'sort_order',
])]
class Book extends Model
{
    protected function casts(): array
    {
        return [
            'role' => BookRole::class,
        ];
    }

    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }

    /** @see Translator::getAvatarUrlAttribute() for why this is relative, not absolute. */
    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path ? '/storage/'.$this->cover_path : null;
    }

    public function hasExcerpt(): bool
    {
        return filled($this->excerpt_source_text) && filled($this->excerpt_translated_text);
    }
}
