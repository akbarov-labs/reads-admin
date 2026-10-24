<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use App\Models\Concerns\HasSearchKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'slug',
    'name',
    'original_name',
    'bio',
    'nationality',
    'birth_year',
    'death_year',
    'portrait_path',
    'website_url',
])]
class Author extends Model
{
    use GeneratesUniqueSlug;
    use HasSearchKey;
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['bio', 'nationality'];

    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'death_year' => 'integer',
        ];
    }

    /**
     * The public site routes on slug (/author/tolstoy), but /api/authors/3
     * has always worked and may still be called. Slug is tried first, and a
     * purely numeric value falls back to the primary key, so both forms
     * resolve and neither can be mistaken for the other — a slug is never
     * all digits, because slugs are generated from names.
     *
     * The slug of an author merged into this one finds this one too; the
     * response carries the current slug, and the site redirects to it.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $bySlug = $this->where('slug', $value)->first()
            ?? $this->whereHas('aliases', fn ($alias) => $alias->where('slug', $value))->first();

        if ($bySlug !== null) {
            return $bySlug;
        }

        return ctype_digit((string) $value) ? $this->whereKey($value)->first() : null;
    }

    /** @return BelongsToMany<Book, $this> */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_author')->withPivot('position');
    }

    /** @return HasMany<AuthorAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(AuthorAlias::class);
    }

    /** A merged author's old slug still leads to its page, so nobody new gets it. */
    protected static function slugTaken(string $slug): bool
    {
        return static::where('slug', $slug)->exists() || AuthorAlias::where('slug', $slug)->exists();
    }

    public function getPortraitUrlAttribute(): ?string
    {
        return $this->portrait_path ? '/storage/'.$this->portrait_path : null;
    }
}
