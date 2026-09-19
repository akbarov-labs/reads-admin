<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'slug',
    'name',
    'bio',
    'nationality',
    'birth_year',
    'death_year',
    'portrait_path',
    'website_url',
])]
class Author extends Model
{
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
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $bySlug = $this->where('slug', $value)->first();

        if ($bySlug !== null) {
            return $bySlug;
        }

        return ctype_digit((string) $value) ? $this->whereKey($value)->first() : null;
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function getPortraitUrlAttribute(): ?string
    {
        return $this->portrait_path ? '/storage/'.$this->portrait_path : null;
    }
}