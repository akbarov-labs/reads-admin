<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use App\Observers\LibraryObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * A library (kutubxona) readers can borrow from. Its owner, where it has a
 * login, keeps the page and the list of books it holds; see LibraryHolding.
 */
#[ObservedBy(LibraryObserver::class)]
#[Fillable([
    'user_id',
    'slug',
    'name',
    'description',
    'region',
    'district',
    'address',
    'latitude',
    'longitude',
    'hours',
    'membership',
    'phone',
    'telegram',
    'instagram',
    'website_url',
    'photo_path',
    'is_published',
    'holdings_updated_at',
])]
class Library extends Model
{
    use GeneratesUniqueSlug;
    use HasTranslations;

    /**
     * Past this, the site says a library's list may be out of date: a
     * "bor" nobody has confirmed in two months is a guess.
     */
    public const STALE_AFTER_DAYS = 60;

    /** @var array<int, string> */
    public array $translatable = ['description', 'hours', 'membership'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'holdings_updated_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /** The login that maintains this library, where one has been set up. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LibraryHolding, $this> */
    public function holdings(): HasMany
    {
        return $this->hasMany(LibraryHolding::class);
    }

    /** @return HasMany<LibraryImport, $this> */
    public function imports(): HasMany
    {
        return $this->hasMany(LibraryImport::class);
    }

    /** @return BelongsToMany<Book, $this> */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'library_holdings')
            ->withPivot(['edition_id', 'copies_total', 'copies_available'])
            ->withTimestamps();
    }

    /** @param  Builder<Library>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('libraries.is_published', true);
    }

    /** Stamps "the list was looked at now", which the site shows readers. */
    public function touchHoldings(): void
    {
        $this->forceFill(['holdings_updated_at' => now()])->saveQuietly();
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? '/storage/'.$this->photo_path : null;
    }

    /** A bare @handle or t.me link, as an owner may type either. */
    public function getTelegramUrlAttribute(): ?string
    {
        return self::profileUrl($this->telegram, 't.me');
    }

    public function getInstagramUrlAttribute(): ?string
    {
        return self::profileUrl($this->instagram, 'instagram.com');
    }

    private static function profileUrl(?string $value, string $host): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $value)) {
            return $value;
        }

        $handle = preg_replace('~^(@|(www\.)?'.preg_quote($host, '~').'/)~i', '', $value);

        return "https://{$host}/".ltrim((string) $handle, '/');
    }
}
