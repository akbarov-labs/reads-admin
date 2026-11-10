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
use Illuminate\Support\Facades\DB;

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
     * Set on the books of a list that asked for one publisher's
     * (/api/books?publisher=): the card then shows that publisher's
     * printing. Not a column; it lives for the request only.
     */
    public ?string $cardPublisherSlug = null;

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
     *
     * In a list of one publisher's books ($cardPublisherSlug) that
     * publisher's own printing comes first, cover or not: on Oltin qalam's
     * page the card is Oltin qalam's edition, not a newer one from another
     * house.
     */
    public function getPrimaryEditionAttribute(): ?Edition
    {
        $slug = $this->cardPublisherSlug;

        return $this->approvedEditions
            ->sortBy(fn (Edition $edition) => [
                $slug !== null && $edition->publisher?->slug !== $slug ? 1 : 0,
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

        $edition = $this->aboutEdition();

        return $edition ? trim((string) $edition->description) : null;
    }

    /**
     * Where the text in `about` was copied from, so the page can credit it:
     * the Asaxiy listing of the edition it came from. Null for the book's
     * own description and for text from anywhere else.
     *
     * @return array{name: string, url: string}|null
     */
    public function getAboutSourceAttribute(): ?array
    {
        if (filled($this->description)) {
            return null;
        }

        $url = (string) $this->aboutEdition()?->source_url;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'asaxiy.uz' || str_ends_with($host, '.asaxiy.uz')
            ? ['name' => 'Asaxiy', 'url' => $url]
            : null;
    }

    /** The approved edition whose description stands in for the book's own. */
    private function aboutEdition(): ?Edition
    {
        return $this->approvedEditions
            ->filter(function (Edition $edition) {
                $text = trim((string) $edition->description);

                return mb_strlen($text) >= self::SHORTEST_ABOUT
                    && preg_match(self::SHOP_TEXT, UzbekText::normalizeApostrophes($text)) !== 1;
            })
            ->sortByDesc(fn (Edition $edition) => mb_strlen(trim((string) $edition->description)))
            ->first();
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot('is_primary')->orderByPivot('is_primary', 'desc');
    }

    /** The book's genre: its primary shelf. Needs `categories` loaded or loads it. */
    public function genre(): ?Category
    {
        return $this->categories->first(fn (Category $category) => (bool) $category->pivot->is_primary);
    }

    /**
     * Makes a shelf the book's genre, putting it on that shelf if it isn't
     * yet, and every other shelf secondary; null leaves it with no genre but
     * on all its shelves. The genre is part of what the Kitoblarim bot
     * shares, so the book's editions are moved up the bot's feed when it
     * changes. Returns whether it changed.
     */
    public function setGenre(?Category $genre): bool
    {
        $current = array_map('intval', DB::table('book_category')->where('book_id', $this->id)->where('is_primary', true)->pluck('category_id')->all());

        if ($current === ($genre !== null ? [$genre->id] : [])) {
            return false;
        }

        DB::transaction(function () use ($genre) {
            DB::table('book_category')->where('book_id', $this->id)->update(['is_primary' => false]);

            if ($genre !== null) {
                $this->categories()->syncWithoutDetaching([$genre->id => ['is_primary' => true]]);
            }

            $this->editions()->toBase()->update(['updated_at' => now()]);
        });

        $this->unsetRelation('categories');

        return true;
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

    /** @return HasMany<LibraryHolding, $this> */
    public function libraryHoldings(): HasMany
    {
        return $this->hasMany(LibraryHolding::class);
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
