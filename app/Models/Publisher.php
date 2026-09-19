<?php

namespace App\Models;

use App\Observers\PublisherObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
])]
class Publisher extends Model
{
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

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? '/storage/'.$this->logo_path : null;
    }
}