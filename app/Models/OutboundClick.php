<?php

namespace App\Models;

use App\Enums\OutboundClickKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A reader the site sent to a store (see the create_outbound_clicks_table
 * migration). Written once, never edited, so there is no updated_at.
 */
#[Fillable([
    'kind',
    'book_id',
    'target',
    'locale',
    'visitor_hash',
    'created_at',
])]
class OutboundClick extends Model
{
    public const UPDATED_AT = null;

    /**
     * Days are counted in Tashkent, where the readers are: a click at 23:30
     * there is that day's, not the next UTC day's. Uzbekistan keeps no
     * daylight saving, so a fixed offset is exact.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    protected function casts(): array
    {
        return [
            'kind' => OutboundClickKind::class,
            'created_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * The keyed hash a visitor is counted by: the same address and browser
     * give the same value all day in Tashkent and a different one the next.
     * Keyed with the app key, so a list of addresses cannot be hashed and
     * matched against the table.
     */
    public static function visitorHash(?string $ip, ?string $userAgent, ?CarbonImmutable $at = null): ?string
    {
        if (($ip ?? '') === '' && ($userAgent ?? '') === '') {
            return null;
        }

        $day = ($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->toDateString();

        return hash_hmac('sha256', $day.'|'.$ip.'|'.$userAgent, (string) config('app.key'));
    }

    /**
     * Clicks from the start of one Tashkent day to the end of another,
     * either end left open.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBetweenDays(Builder $query, ?string $from, ?string $until): void
    {
        if ($from) {
            $query->where('created_at', '>=', CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay()->utc());
        }

        if ($until) {
            $query->where('created_at', '<=', CarbonImmutable::parse($until, self::TIMEZONE)->endOfDay()->utc());
        }
    }

    /** SQL for a click's Tashkent date (YYYY-MM-DD), for grouping by day. */
    public static function daySql(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date(created_at, '+5 hours')",
            'pgsql' => "to_char(created_at + interval '5 hours', 'YYYY-MM-DD')",
            default => 'DATE(DATE_ADD(created_at, INTERVAL 5 HOUR))',
        };
    }
}
