<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use App\Enums\ModerationStatus;
use App\Models\Concerns\HasSearchKey;
use App\Observers\PublisherObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Translatable\HasTranslations;

#[ObservedBy(PublisherObserver::class)]
#[Fillable([
    'user_id',
    'slug',
    'name',
    'bio',
    'country',
    'established_year',
    'logo_path',
    'website_url',
    'telegram_channel',
    'order_contact',
])]
class Publisher extends Model
{
    use GeneratesUniqueSlug;
    use HasSearchKey;
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['bio', 'country'];

    protected function casts(): array
    {
        return ['established_year' => 'integer'];
    }


    /**
     * The public site routes on slug (/author/tolstoy), but /api/authors/3
     * has always worked and may still be called. Slug is tried first, and a
     * purely numeric value falls back to the primary key, so both forms
     * resolve and neither can be mistaken for the other — a slug is never
     * all digits, because slugs are generated from names.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $bySlug = $this->where('slug', $value)->first();

        if ($bySlug !== null) {
            return $bySlug;
        }

        return ctype_digit((string) $value) ? $this->whereKey($value)->first() : null;
    }

    /** The login that maintains this publisher, where one has been set up. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Announcement, $this> */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /** @return HasMany<Edition, $this> */
    public function editions(): HasMany
    {
        return $this->hasMany(Edition::class);
    }

    /**
     * The works this publisher has printed, through their editions. Two
     * editions of one book are one book here; see scopeWithBookCount() for
     * counting them that way too.
     *
     * @return BelongsToMany<Book, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'editions')->distinct();
    }

    /**
     * Adds `books_count`: distinct books with an edition from this
     * publisher. withCount('books') would count editions, because the
     * relation's pivot table is `editions`.
     *
     * @param  Builder<Publisher>  $query
     */
    public function scopeWithBookCount(Builder $query, bool $approvedOnly = false): void
    {
        $query->withCount(['editions as books_count' => function (Builder $editions) use ($approvedOnly) {
            $editions->select(DB::raw('count(distinct editions.book_id)'));

            if ($approvedOnly) {
                $editions->approved()->whereHas('book', fn (Builder $book) => $book->where('books.status', ModerationStatus::Approved));
            }
        }]);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.$this->logo_path : null;
    }
}