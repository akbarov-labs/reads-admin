<?php

namespace App\Services\Telegram;

use App\Enums\DealKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A first guess at a deal's fields from a channel post, for an admin to
 * check before publishing: a sale ("chegirma", "скидка", "aksiya", a
 * percentage off, a promo code), a contest with books to win ("konkurs",
 * "tanlovga", "розыгрыш"), or an event to go to (a meeting with an author
 * or translator, a launch, a signing, with a time). A pre-order's discount
 * is not one: that is a new book, and AnnouncementDraft takes it.
 *
 * Some channels write in the newer Latin spelling (ç, ş, ğ for ch, sh, gʻ:
 * "uçraşuv", "ğolib"), so the words allow both.
 */
final class DealDraft
{
    private const SALE_WORDS = '/chegirma|чегирма|скидк|aksiya|акция|распродаж|arzonlashtir|арзонлаштир|promo\s*-?\s*kod|промо\s*-?\s*код|\bsale\b|black\s+friday|\d{1,2}\s*%\s*(?:gacha|гача|chegirma|чегирма|скидк|off|арзон|arzon)/iu';

    /**
     * A bare "tanlov" is as often "a choice" ("bir lahzalik tanlov") as a
     * contest, so only its contest forms count: "tanlovga", "tanlovda",
     * "tanlov e'lon qilamiz".
     */
    private const CONTEST_WORDS = '/konkurs|конкурс|tanlov(?:ga|da|imiz|ning)(?!\p{L})|tanlov\s+e[\'ʻ‘’`ʼ]?lon|танлов(?:га|да|имиз)(?!\p{L})|танлов\s+эълон|розыгрыш|giveaway|sovg[\'ʻ‘’`]?a\s+qil|совға\s+қил|yutib\s+oling|ютиб\s+олинг|(?:g[\'ʻ‘’`]?|ğ)olib\w*\s+(?:bo[\'ʻ‘’`]?l|aniql)|ғолиб\w*\s+(?:бўл|аниқл)/iu';

    /** A contest's winners being named is the end of one, not a chance to enter. */
    private const RESULTS_WORDS = '/(?:g[\'ʻ‘’`]?|ğ)olib\w*\s+aniqlan|ғолиб\w*\s+аниқлан|итоги\s+(?:розыгрыш|конкурс)|победител\w*\s+(?:определ|объявл)/iu';

    /** A meeting, a launch, a signing: somewhere to be. */
    private const EVENT_WORDS = '/u(?:ch|ç)ra(?:sh|ş)uv|учрашув|taqdimot|тақдимот|avtograf|автограф|imzo\s+(?:qo[\'ʻ‘’`]?y|kun)|имзо\s+қўй|встреч[аиуе](?!\p{L})|презентаци|автограф-сесси/iu';

    /** …at a time: "soat 17:00", "17:00 da", "в 18:00". An event is posted with one. */
    private const EVENT_TIME = '/(?<!\d)(?:[01]?\d|2[0-3])[:.][0-5]\d(?!\d)/u';

    /** The lines a reader needs first: when and where. */
    private const WHEN_WHERE = '/^[🗓📅📆⏰🕐🕑🕒🕓🕔🕕🕖🕗🕘🕙🕚🕛📍🏢]|^(?:sana|vaqt|soat|manzil|joy|сана|вақт|соат|манзил|дата|время|место|адрес)\s*:/iu';

    public static function looksLikeDeal(?string $text): bool
    {
        $text = (string) $text;

        if (mb_strlen(trim($text)) < 40 || preg_match(AnnouncementDraft::PREORDER_WORDS, $text)) {
            return false;
        }

        if (preg_match(self::RESULTS_WORDS, $text)) {
            return false;
        }

        return (bool) (preg_match(self::SALE_WORDS, $text) || preg_match(self::CONTEST_WORDS, $text) || self::isEvent($text));
    }

    /**
     * A meeting or launch with a time to come. Without one, "taqdimot" is
     * more often a new book's news, and "uchrashuv" a report of one held.
     */
    private static function isEvent(string $text): bool
    {
        return preg_match(self::EVENT_WORDS, $text) && preg_match(self::EVENT_TIME, $text);
    }

    /**
     * @return array{kind: string, title: string, discount_percent: ?int, promo_code: ?string, ends_on: ?string, details: ?string}
     */
    public static function fromText(?string $text, ?CarbonImmutable $postedAt = null): array
    {
        $text = (string) $text;
        $lines = array_values(array_filter(array_map(
            fn (string $line) => trim($line),
            preg_split('/\R/u', $text),
        ), fn (string $line) => preg_match('/\p{L}/u', $line)));

        $sale = (bool) preg_match('/chegirma|чегирма|скидк|\d{1,2}\s*%/iu', $text);
        $kind = match (true) {
            ! $sale && self::isEvent($text) => DealKind::Event,
            ! $sale && preg_match(self::CONTEST_WORDS, $text) => DealKind::Contest,
            default => DealKind::Discount,
        };

        $details = array_slice($lines, 1);

        if ($kind === DealKind::Event) {
            // When and where first, so the 600 characters never cut them off.
            $whenWhere = array_filter($details, fn (string $line) => preg_match(self::WHEN_WHERE, $line));
            $details = [...$whenWhere, ...array_diff_key($details, $whenWhere)];
        }

        return [
            'kind' => $kind->value,
            'title' => Str::limit(AnnouncementDraft::title($lines), 120, ''),
            'discount_percent' => self::percent($text),
            'promo_code' => self::promoCode($text),
            // For an event, its day: it leaves the site after it.
            'ends_on' => AnnouncementDraft::deadline(
                // "soat 17.00" is a time, not the 17th of a month.
                $kind === DealKind::Event ? preg_replace('/(?<!\d)\d{1,2}:\d{2}(?!\d)|(?:soat|соат|в)\s+\d{1,2}[.:]\d{2}/iu', ' ', $text) : $text,
                $postedAt ?? CarbonImmutable::now(),
            ),
            'details' => Str::limit(implode("\n", array_slice($details, 0, 5)), 600, '…') ?: null,
        ];
    }

    /** The largest "30%" or "-30 %" in the post, if it's a sensible discount. */
    private static function percent(string $text): ?int
    {
        preg_match_all('/(?<!\d)(\d{1,2})\s*%/u', $text, $m);
        $values = array_filter(array_map('intval', $m[1]), fn (int $value) => $value >= 3 && $value <= 90);

        return $values === [] ? null : max($values);
    }

    /** "Promokod: KITOB30", "промокод KITOB30". */
    private static function promoCode(string $text): ?string
    {
        if (preg_match('/(?:promo\s*-?\s*kod|промо\s*-?\s*код|promo\s*code)\w*\s*[:\-–—]?\s*[«"“]?([A-Za-z0-9_-]{3,32})/iu', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
