<?php

namespace App\Models;

use App\Observers\TranslatorObserver;
use App\Support\TranslatorSeo;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

#[ObservedBy(TranslatorObserver::class)]
#[Fillable([
    'user_id',
    'slug',
    'name',
    'avatar_path',
    'location',
    'bio',
    'title',
    'meta_title',
    'meta_description',
    'og_image_path',
    'seo_noindex',
    'years_active',
    'start_year',
])]
class Translator extends Model
{
    use HasTranslations;

    /**
     * Written in whichever language(s) the translator chooses to fill in —
     * the admin panel's locale tabs let them leave some blank, and reads
     * fall back through routing.locales order (see config/translatable.php).
     *
     * @var array<int, string>
     */
    public array $translatable = ['bio', 'location', 'title', 'meta_title', 'meta_description'];

    protected function casts(): array
    {
        return [
            'seo_noindex' => 'boolean',
        ];
    }

    /**
     * Search-engine metadata for this profile: the admin's overrides where
     * they exist, values derived from the profile everywhere else.
     */
    public function seo(): TranslatorSeo
    {
        return TranslatorSeo::for($this);
    }

    /**
     * Newest change anywhere in the profile, used as the sitemap's
     * <lastmod>. A new book is a change to the page, so the book timestamps
     * count even though the translator row itself did not move.
     */
    public function getContentUpdatedAtAttribute(): ?Carbon
    {
        $timestamps = collect([
            $this->updated_at,
            $this->books->max('updated_at'),
            $this->socialLinks->max('updated_at'),
            $this->languagePairs->max('updated_at'),
        ])->filter();

        return $timestamps->isEmpty() ? null : $timestamps->max();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * `slug` is required and unique, but a translator created inline by a
     * publisher (see PublisherResource\RelationManagers\BooksRelationManager)
     * supplies only a name — nobody is filling in a "URL" field for someone
     * who does not yet have a login to reach a profile page with. This picks
     * one for them; an admin can change it once the profile is filled in.
     */
    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'translator';
        $slug = $base;

        for ($suffix = 2; static::where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LanguagePair, $this> */
    public function languagePairs(): HasMany
    {
        return $this->hasMany(LanguagePair::class)->orderBy('sort_order');
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->orderBy('sort_order');
    }

    /** @return HasMany<Inquiry, $this> */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class)->latest();
    }

    /**
     * Polymorphic since taqrizchilar gained profiles of their own — the same
     * row of links, owned by either kind of profile.
     *
     * @return MorphMany<SocialLink, $this>
     */
    public function socialLinks(): MorphMany
    {
        return $this->morphMany(SocialLink::class, 'owner')->orderBy('sort_order');
    }

    /**
     * A path relative to this API's own origin, e.g. "/storage/avatars/x.jpg" —
     * deliberately not an absolute URL. The API server doesn't know which
     * host name the caller used to reach it (a browser and a server-side
     * Next.js fetch from inside Docker resolve "localhost" differently), so
     * the consumer resolves this against whatever base it used to reach us.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? '/storage/'.$this->avatar_path : null;
    }

    public function getTotalBooksTranslatedAttribute(): int
    {
        return $this->books()->count();
    }
}
