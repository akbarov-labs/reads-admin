<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the site's own statistics (store clicks, book views, searches) share:
 * Tashkent days and the visitor hash.
 */
final class SiteStats
{
    /**
     * Days are counted in Tashkent, where the readers are: a click at 23:30
     * there is that day's, not the next UTC day's. Uzbekistan keeps no
     * daylight saving, so a fixed offset is exact.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    /** A repeat of the same view or search by one visitor within this long is one. */
    public const REPEAT_MINUTES = 30;

    public static function today(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * The keyed hash a visitor is counted by: the same address and browser
     * give the same value all day in Tashkent and a different one the next.
     * Keyed with the app key, so a list of addresses cannot be hashed and
     * matched against the tables. No address is stored anywhere.
     */
    public static function visitorHash(?string $ip, ?string $userAgent, ?CarbonImmutable $at = null): ?string
    {
        if (($ip ?? '') === '' && ($userAgent ?? '') === '') {
            return null;
        }

        return hash_hmac('sha256', self::today($at).'|'.$ip.'|'.$userAgent, (string) config('app.key'));
    }

    /**
     * Rows timestamped from the start of one Tashkent day to the end of
     * another, either end left open.
     */
    public static function betweenDays(Builder $query, ?string $from, ?string $until, string $column = 'created_at'): Builder
    {
        if ($from) {
            $query->where($column, '>=', CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay()->utc());
        }

        if ($until) {
            $query->where($column, '<=', CarbonImmutable::parse($until, self::TIMEZONE)->endOfDay()->utc());
        }

        return $query;
    }

    /** SQL for a timestamp's Tashkent date (YYYY-MM-DD), for grouping by day. */
    public static function daySql(string $column = 'created_at'): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column}, '+5 hours')",
            'pgsql' => "to_char({$column} + interval '5 hours', 'YYYY-MM-DD')",
            default => "DATE(DATE_ADD({$column}, INTERVAL 5 HOUR))",
        };
    }
}
