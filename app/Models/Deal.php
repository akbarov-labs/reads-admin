<?php

namespace App\Models;

use App\Enums\DealKind;
use App\Enums\ModerationStatus;
use App\Enums\OutboundClickKind;
use App\Observers\DealObserver;
use App\Support\SiteStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A discount or contest for readers: a publisher's post (scanned or pasted)
 * or a shop's promotion typed in by an admin. See the create_deals_table
 * migration. Pending until an admin has checked it.
 */
#[ObservedBy(DealObserver::class)]
#[Fillable([
    'kind',
    'publisher_id',
    'shop_name',
    'book_id',
    'title',
    'details',
    'discount_percent',
    'promo_code',
    'starts_on',
    'ends_on',
    'url',
    'source_url',
    'posted_at',
    'post_text',
    'image_path',
    'status',
    'found_by_scan',
])]
class Deal extends Model
{
    /**
     * How long a deal that names no end stays on the site. Sales rarely run
     * longer than a couple of weeks, and an old one shown as live costs a
     * reader a wasted trip.
     */
    public const UNDATED_FOR_DAYS = 14;

    /** @var array<string, mixed> */
    protected $attributes = [
        'kind' => 'discount',
        'status' => ModerationStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'kind' => DealKind::class,
            'status' => ModerationStatus::class,
            'found_by_scan' => 'boolean',
            'posted_at' => 'datetime',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'discount_percent' => 'integer',
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

    /** Who runs it, as the site names them. */
    public function sourceName(): ?string
    {
        return $this->publisher?->name ?? $this->shop_name;
    }

    /** Relative to this app's origin, like Announcement::coverUrl(): the site resolves it. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? '/storage/'.$this->image_path : null;
    }

    /** What a click on it records as its target (OutboundClickKind::Deal). */
    public function clickTarget(): string
    {
        return 'deal-'.$this->id;
    }

    public function clicksCount(): int
    {
        return OutboundClick::query()->where('kind', OutboundClickKind::Deal)->where('target', $this->clickTarget())->count();
    }

    /**
     * On the site today: approved, started (or with no start), and either
     * not past its last day or, with none given, posted in the last
     * UNDATED_FOR_DAYS days. Days are Tashkent days.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $today = SiteStats::today();
        $since = CarbonImmutable::now()->subDays(self::UNDATED_FOR_DAYS);

        $query->where('status', ModerationStatus::Approved)
            ->where(fn (Builder $query) => $query->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn (Builder $query) => $query
                ->where('ends_on', '>=', $today)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('ends_on')
                    ->where(fn (Builder $query) => $query
                        ->where('posted_at', '>=', $since)
                        ->orWhere(fn (Builder $query) => $query->whereNull('posted_at')->where('created_at', '>=', $since))
                    )
                )
            );
    }
}
