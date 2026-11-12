<?php

namespace App\Services\Telegram;

use App\Enums\DealKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A first guess at a deal's fields from a channel post, for an admin to
 * check before publishing: a sale ("chegirma", "скидка", "aksiya", a
 * percentage off, a promo code) or a contest with books to win ("konkurs",
 * "tanlov", "розыгрыш"). A pre-order's discount is not one: that is a new
 * book, and AnnouncementDraft takes it.
 */
final class DealDraft
{
    private const SALE_WORDS = '/chegirma|чегирма|скидк|aksiya|акция|распродаж|arzonlashtir|арзонлаштир|promo\s*-?\s*kod|промо\s*-?\s*код|\bsale\b|black\s+friday|\d{1,2}\s*%\s*(?:gacha|гача|chegirma|чегирма|скидк|off|арзон|arzon)/iu';

    private const CONTEST_WORDS = '/konkurs|конкурс|tanlov|танлов|розыгрыш|giveaway|sovg[\'ʻ‘’`]?a\s+qil|совға\s+қил|g[\'ʻ‘’`]?olib\w*\s+(?:bo[\'ʻ‘’`]?l|aniql)|ғолиб\w*\s+(?:бўл|аниқл)/iu';

    /** A contest's winners being named is the end of one, not a chance to enter. */
    private const RESULTS_WORDS = '/g[\'ʻ‘’`]?olib\w*\s+aniqlan|ғолиб\w*\s+аниқлан|итоги\s+(?:розыгрыш|конкурс)|победител\w*\s+(?:определ|объявл)/iu';

    public static function looksLikeDeal(?string $text): bool
    {
        $text = (string) $text;

        if (mb_strlen(trim($text)) < 40 || preg_match(AnnouncementDraft::PREORDER_WORDS, $text)) {
            return false;
        }

        if (preg_match(self::RESULTS_WORDS, $text)) {
            return false;
        }

        return (bool) (preg_match(self::SALE_WORDS, $text) || preg_match(self::CONTEST_WORDS, $text));
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

        $contest = preg_match(self::CONTEST_WORDS, $text) && ! preg_match('/chegirma|чегирма|скидк|\d{1,2}\s*%/iu', $text);

        return [
            'kind' => ($contest ? DealKind::Contest : DealKind::Discount)->value,
            'title' => Str::limit(AnnouncementDraft::title($lines), 120, ''),
            'discount_percent' => self::percent($text),
            'promo_code' => self::promoCode($text),
            'ends_on' => AnnouncementDraft::deadline($text, $postedAt ?? CarbonImmutable::now()),
            'details' => Str::limit(implode("\n", array_slice($lines, 1, 6)), 600, '…') ?: null,
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
