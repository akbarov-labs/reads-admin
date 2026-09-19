<?php

namespace App\Models;

use App\Observers\TaqrizchiObserver;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Translatable\HasTranslations;

/**
 * A book reviewer with their own login and public profile. Shaped after
 * Translator on purpose — see the create_taqrizchilar_table migration for
 * what it deliberately does not copy.
 */
#[ObservedBy(TaqrizchiObserver::class)]
#[Fillable([
    'user_id',
    'slug',
    'name',
    'avatar_path',
    'location',
    'bio',
    'title',
])]
class Taqrizchi extends Model
{
    use HasTranslations;

    /**
     * The table is the Uzbek plural, not Laravel's guess ("taqrizchis").
     */
    protected $table = 'taqrizchilar';

    /** @var array<int, string> */
    public array $translatable = ['bio', 'location', 'title'];

    /**
     * @see Translator::getContentUpdatedAtAttribute() — a new taqriz changes
     * what the profile page says, so it counts even though the profile row
     * itself did not move.
     */
    public function getContentUpdatedAtAttribute(): ?Carbon
    {
        $timestamps = collect([
            $this->updated_at,
            $this->taqrizlar->max('updated_at'),
            $this->socialLinks->max('updated_at'),
        ])->filter();

        return $timestamps->isEmpty() ? null : $timestamps->max();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Taqriz, $this> */
    public function taqrizlar(): HasMany
    {
        return $this->hasMany(Taqriz::class)->latest();
    }

    /** @return MorphMany<SocialLink, $this> */
    public function socialLinks(): MorphMany
    {
        return $this->morphMany(SocialLink::class, 'owner')->orderBy('sort_order');
    }

    /** @see Translator::getAvatarUrlAttribute() for why this is relative, not absolute. */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? '/storage/'.$this->avatar_path : null;
    }

    /**
     * Only approved reviews count towards anything public — a pending or
     * rejected taqriz must not inflate a profile's visible output.
     */
    public function getTotalTaqrizlarAttribute(): int
    {
        return $this->taqrizlar()->approved()->count();
    }
}
