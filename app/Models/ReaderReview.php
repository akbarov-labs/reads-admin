<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Observers\ReaderReviewObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review a Kitoblarim bot reader wrote and chose to show on the site,
 * signed with their first name. It arrives through the catalogue sync
 * (App\Services\ReaderFeedbackSync) and is shown only once a moderator
 * approves it; an edit in the bot sends it back for review.
 */
#[ObservedBy(ReaderReviewObserver::class)]
#[Fillable([
    'external_id',
    'book_id',
    'edition_id',
    'author_name',
    'rating',
    'body',
    'status',
    'written_at',
])]
class ReaderReview extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'rating' => 'integer',
            'written_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class);
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('reader_reviews.status', ModerationStatus::Approved);
    }
}
