<?php

namespace App\Models;

use App\Enums\BookRole;
use App\Observers\ProfileContentObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ProfileContentObserver::class)]
#[Fillable([
    'translator_id',
    'author_id',
    'publisher_id',
    'role',
    'uzbek_title',
    'original_title',
    'author',
    'author_image_path',
    'source_language',
    'publisher',
    'publisher_image_path',
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

    /**
     * Optional: a book can reach the catalogue without anyone having
     * translated it — a taqrizchi adding a book they want to review, or an
     * original-language work.
     */
    public function translator(): BelongsTo
    {
        return $this->belongsTo(Translator::class);
    }

    /** @return HasMany<Taqriz, $this> */
    public function taqrizlar(): HasMany
    {
        return $this->hasMany(Taqriz::class)->latest();
    }

    public function authorRecord(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function publisherRecord(): BelongsTo
    {
        return $this->belongsTo(Publisher::class, 'publisher_id');
    }

    /** @see Translator::getAvatarUrlAttribute() for why this is relative, not absolute. */
    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path ? '/storage/'.$this->cover_path : null;
    }

    /** Relative URL for the author's photo, or null when not set. */
    public function getAuthorImageUrlAttribute(): ?string
    {
        return $this->author_image_path ? '/storage/'.$this->author_image_path : null;
    }

    /** Relative URL for the publisher's logo/image, or null when not set. */
    public function getPublisherImageUrlAttribute(): ?string
    {
        return $this->publisher_image_path ? '/storage/'.$this->publisher_image_path : null;
    }

    public function hasExcerpt(): bool
    {
        return filled($this->excerpt_source_text) && filled($this->excerpt_translated_text);
    }
}
