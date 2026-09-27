<?php

namespace App\Models;

use App\Enums\TaqrizStatus;
use App\Observers\TaqrizObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * One reviewer's take on one book. See the create_taqrizlar_table migration
 * for why the score is five columns rather than one.
 */
#[ObservedBy(TaqrizObserver::class)]
#[Fillable([
    'edition_id',
    'taqrizchi_id',
    'body',
    'youtube_url',
    'score_plot',
    'score_style',
    'score_translation',
    'score_cover',
    'score_overall',
    'status',
])]
class Taqriz extends Model
{
    protected $table = 'taqrizlar';

    /**
     * The database has this default too, but a default only the database
     * knows leaves `$taqriz->status` null on a freshly created model until
     * something refetches it — so moderation checks would read null rather
     * than "pending" at exactly the moment a review is submitted.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => TaqrizStatus::Pending->value,
    ];

    /**
     * The five aspects, in the order they are shown to a reviewer and to a
     * reader. Keyed by column so the form, the API and the average all read
     * from one list instead of repeating it.
     *
     * @var array<string, string>
     */
    public const ASPECTS = [
        'score_plot' => 'Syujet va voqealar rivoji',
        'score_style' => 'Uslub va til',
        'score_translation' => 'Tarjima sifati',
        'score_cover' => 'Muqova va nashr sifati',
        'score_overall' => 'Umumiy taassurot',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaqrizStatus::class,
            // Cast to float rather than decimal: decimal casts hand back
            // strings, and every consumer here does arithmetic or JSON.
            'score_plot' => 'float',
            'score_style' => 'float',
            'score_translation' => 'float',
            'score_cover' => 'float',
            'score_overall' => 'float',
        ];
    }

    /**
     * The API's name for an aspect: the column without its `score_` prefix,
     * e.g. score_plot => "plot". Stable and language-free, so the site can
     * attach its own localized label rather than rendering one we chose.
     */
    public static function aspectKey(string $column): string
    {
        return str_replace('score_', '', $column);
    }

    /** @param  Builder<Taqriz>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('taqrizlar.status', TaqrizStatus::Approved);
    }

    /** The edition the reviewer read: its translation and its printing are what get scored. */
    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    /** @return HasOneThrough<Book, Edition, $this> */
    public function book(): HasOneThrough
    {
        return $this->hasOneThrough(Book::class, Edition::class, 'id', 'id', 'edition_id', 'book_id');
    }

    public function taqrizchi(): BelongsTo
    {
        return $this->belongsTo(Taqrizchi::class);
    }

    /**
     * Whichever aspects the reviewer actually scored, keyed by column.
     *
     * @return array<string, float>
     */
    public function scoredAspects(): array
    {
        $scores = [];

        foreach (array_keys(self::ASPECTS) as $column) {
            if ($this->{$column} !== null) {
                $scores[$column] = (float) $this->{$column};
            }
        }

        return $scores;
    }

    /**
     * The headline figure, averaged over the aspects that were filled in.
     *
     * Dividing by a hardcoded five would be wrong: "Tarjima sifati" is hidden
     * for a book with no translator, so a perfect review of an untranslated
     * book would otherwise score 8 out of 10 for having answered four
     * questions instead of five.
     */
    public function getAverageScoreAttribute(): ?float
    {
        $scores = $this->scoredAspects();

        return $scores === [] ? null : round(array_sum($scores) / count($scores), 1);
    }
}
