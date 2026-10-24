<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Models\Concerns\HasSearchKey;
use App\Observers\ProfileContentObserver;
use App\Support\UzbekText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * The work: what a reader means by "a book". Everything that differs from
 * one printing to the next — publisher, ISBN, year, cover, translators, the
 * translator's excerpt — lives on its editions. See docs/DATA-MODEL.md.
 */
#[ObservedBy(ProfileContentObserver::class)]
#[Fillable([
    'uzbek_title',
    'original_title',
    'source_language',
    'description',
    'series_id',
    'series_position',
    'sort_order',
    'status',
])]
class Book extends Model
{
    use HasSearchKey;

    /**
     * Mirrors the database default so a freshly built model reports its real
     * state before it has been saved and read back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ModerationStatus::Approved->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'series_position' => 'float',
        ];
    }

    /** @return BelongsToMany<Author, $this> */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_author')->withPivot('position')->orderByPivot('position');
    }

    /** @return HasMany<Edition, $this> */
    public function editions(): HasMany
    {
        return $this->hasMany(Edition::class)->orderBy('sort_order')->orderByDesc('year')->orderBy('id');
    }

    /** @return HasMany<Edition, $this> */
    public function approvedEditions(): HasMany
    {
        return $this->editions()->where('editions.status', ModerationStatus::Approved);
    }

    /**
     * The edition that stands for the book in a list: approved, with a cover
     * if any has one, then the newest. Chosen in PHP from approvedEditions —
     * a book has a handful of editions, and eager-loading them all for a
     * page of twenty books is cheaper than any SQL that ranks them.
     */
    public function getPrimaryEditionAttribute(): ?Edition
    {
        return $this->approvedEditions
            ->sortBy(fn (Edition $edition) => [
                $edition->cover_path === null ? 1 : 0,
                -($edition->year ?? 0),
                $edition->sort_order,
                $edition->id,
            ])
            ->first();
    }

    /** A shop's listing line, not a word about the book: "… do'konida. Buyurtma bering, yetkazib beramiz". */
    private const SHOP_TEXT = "/do'kon|buyurtma|yetkazib|магазин|заказ|доставк/iu";

    /** Shorter than this, an edition's text is a note about the printing ("Yangi nashr … tahrir qilingan"). */
    private const SHORTEST_ABOUT = 100;

    /**
     * What the book page says about the book: its own description, or else
     * the fullest one an approved edition carries — that is where the Asaxiy
     * import and the bot put them, so almost no book has its own. A shop's
     * sales line and a short note about one printing are not descriptions of
     * the book, and are skipped.
     */
    public function getAboutAttribute(): ?string
    {
        if (filled($this->description)) {
            return $this->description;
        }

        return $this->approvedEditions
            ->map(fn (Edition $edition) => trim((string) $edition->description))
            ->filter(fn (string $text) => mb_strlen($text) >= self::SHORTEST_ABOUT
                && preg_match(self::SHOP_TEXT, UzbekText::normalizeApostrophes($text)) !== 1)
            ->sortByDesc(fn (string $text) => mb_strlen($text))
            ->first();
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot('is_primary')->orderByPivot('is_primary', 'desc');
    }

    /** @return BelongsTo<Series, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /** @return BelongsToMany<Book, $this> */
    public function relatedBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_relations', 'book_id', 'related_book_id')
            ->withPivot(['source', 'position'])
            ->orderByPivot('position');
    }

    /**
     * Every review of any edition of this book, newest first.
     *
     * @return HasManyThrough<Taqriz, Edition, $this>
     */
    /** @return HasMany<Quote, $this> */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('quotes.id');
    }

    /** Reviews from Kitoblarim bot readers (App\Models\ReaderReview). */
    public function readerReviews(): HasMany
    {
        return $this->hasMany(ReaderReview::class)->latest('written_at');
    }

    public function taqrizlar(): HasManyThrough
    {
        return $this->hasManyThrough(Taqriz::class, Edition::class)->latest('taqrizlar.created_at');
    }

    /** Authors' names as one line: "Ellen Feyn, Sherri Shnayder". */
    public function getAuthorNamesAttribute(): ?string
    {
        $names = $this->authors->pluck('name');

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    /**
     * On the public site: approved, and with at least one approved edition
     * to show for it.
     *
     * @param  Builder<Book>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('books.status', ModerationStatus::Approved)
            ->whereHas('editions', fn (Builder $editions) => $editions->where('editions.status', ModerationStatus::Approved));
    }
}
