<?php

namespace App\Models;

use App\Enums\ModerationStatus;
use App\Observers\AnnouncementObserver;
use App\Support\SiteStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A publisher's new book or discounted pre-order, made from a post on its
 * public Telegram channel (see the create_announcements_table migration and
 * App\Services\TelegramChannel). Pending until an admin has checked the
 * fields against the post.
 */
#[ObservedBy(AnnouncementObserver::class)]
#[Fillable([
    'publisher_id',
    'book_id',
    'source_url',
    'posted_at',
    'post_text',
    'title',
    'author_name',
    'translator_name',
    'price',
    'preorder_price',
    'preorder_deadline',
    'cover_path',
    'status',
])]
class Announcement extends Model
{
    /**
     * How long a new book without a pre-order deadline stays on the site.
     * "New" stops being news; a pre-order leaves on its own deadline.
     */
    public const NEW_FOR_DAYS = 60;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ModerationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => ModerationStatus::class,
            'posted_at' => 'datetime',
            'preorder_deadline' => 'date',
            'price' => 'integer',
            'preorder_price' => 'integer',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(OutboundClick::class);
    }

    /** A pre-order rather than a book already in print. */
    public function isPreorder(): bool
    {
        return $this->preorder_deadline !== null || $this->preorder_price !== null;
    }

    /** Relative to this app's origin, as Edition::cover_url: the site resolves it. */
    public function coverUrl(): ?string
    {
        return $this->cover_path ? '/storage/'.$this->cover_path : null;
    }

    /**
     * What the site shows today: approved, and either a pre-order whose
     * deadline (a Tashkent day) has not passed, or a new book posted in the
     * last NEW_FOR_DAYS days.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $today = SiteStats::today();
        $since = CarbonImmutable::now()->subDays(self::NEW_FOR_DAYS);

        $query->where('status', ModerationStatus::Approved)
            ->where(fn (Builder $query) => $query
                ->where('preorder_deadline', '>=', $today)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('preorder_deadline')
                    ->where(fn (Builder $query) => $query
                        ->where('posted_at', '>=', $since)
                        ->orWhere(fn (Builder $query) => $query->whereNull('posted_at')->where('created_at', '>=', $since))
                    )
                )
            );
    }
}
