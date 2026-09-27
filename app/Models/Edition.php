<?php

namespace App\Models;

use App\Enums\BookRole;
use App\Enums\ModerationStatus;
use App\Observers\ProfileContentObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One printing of a book: this publisher, this ISBN, this year, translated
 * by these people. A translator's portfolio and a publisher's list are both
 * lists of editions; the book is what they have in common.
 */
#[ObservedBy(ProfileContentObserver::class)]
#[Fillable([
    'book_id',
    'publisher_id',
    'title',
    'isbn',
    'language',
    'script',
    'year',
    'pages',
    'cover_type',
    'cover_material',
    'paper_format',
    'page_surface',
    'print_country',
    'weight',
    'dimensions',
    'volume_count',
    'volume_part',
    'cover_path',
    'description',
    'excerpt_source_language',
    'excerpt_source_text',
    'excerpt_translated_text',
    'status',
    'sort_order',
    'source_url',
    'source_article',
])]
class Edition extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationStatus::Approved->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'year' => 'integer',
            'pages' => 'integer',
            'volume_count' => 'integer',
            'volume_part' => 'integer',
        ];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** @return BelongsTo<Publisher, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /**
     * Everyone credited on this edition, with what they did (translator,
     * editor, or author for a translator's own book).
     *
     * @return BelongsToMany<Translator, $this>
     */
    public function translators(): BelongsToMany
    {
        return $this->belongsToMany(Translator::class)
            ->using(EditionTranslator::class)
            ->withPivot(['role', 'position'])
            ->orderByPivot('position');
    }

    /**
     * The same credits as rows in their own right, for forms that edit the
     * list — who, which role, in which order — rather than just membership.
     *
     * @return HasMany<EditionTranslator, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(EditionTranslator::class)->orderBy('position');
    }

    /** @return HasMany<EditionImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(EditionImage::class)->orderBy('position');
    }

    /** @return HasMany<Taqriz, $this> */
    public function taqrizlar(): HasMany
    {
        return $this->hasMany(Taqriz::class)->latest();
    }

    /** As printed, or the book's own title when this edition doesn't differ. */
    public function getDisplayTitleAttribute(): string
    {
        return $this->title ?: (string) $this->book?->uzbek_title;
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

    /** Whether anybody translated this edition, as opposed to it being an original. */
    public function isTranslated(): bool
    {
        return $this->translators->contains(fn (Translator $t) => $t->pivot->role === BookRole::Translator);
    }

    /** @param  Builder<Edition>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('editions.status', ModerationStatus::Approved);
    }
}
