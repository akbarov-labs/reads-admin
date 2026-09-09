<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'user_id',
    'slug',
    'name',
    'avatar_path',
    'location',
    'bio',
    'title',
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
    public array $translatable = ['bio', 'location', 'title'];

    public function getRouteKeyName(): string
    {
        return 'slug';
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

    /** @return HasMany<SocialLink, $this> */
    public function socialLinks(): HasMany
    {
        return $this->hasMany(SocialLink::class)->orderBy('sort_order');
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
