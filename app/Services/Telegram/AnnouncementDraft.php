<?php

namespace App\Services\Telegram;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A first guess at an announcement's fields from a post's text, for an
 * admin to correct before approving: posts are written by hand, every
 * publisher its own way. Anything not recognised is left empty rather than
 * guessed wildly.
 */
final class AnnouncementDraft
{
    private const PREORDER_WORDS = '/oldindan\s+buyurtma|old\.?\s*buyurtma|predzakaz|предзаказ|предварительн\w*\s+заказ|pre-?order/iu';

    // Latin month names take Uzbek suffixes (oktabrgacha, 25-oktabrda);
    // Cyrillic ones Russian case endings (октября, марта). Nothing else may
    // follow, so "25 marta" (25 times) is not March.
    private const LATIN_MONTHS = [
        'yanvar' => 1, 'fevral' => 2, 'mart' => 3, 'aprel' => 4, 'may' => 5, 'iyun' => 6,
        'iyul' => 7, 'avgust' => 8, 'sentabr' => 9, 'sentyabr' => 9, 'oktabr' => 10, 'oktyabr' => 10,
        'noyabr' => 11, 'dekabr' => 12,
    ];

    private const CYRILLIC_MONTHS = [
        'январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'май' => 5, 'мая' => 5, 'июн' => 6,
        'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
    ];

    /**
     * @return array{title: string, author_name: ?string, translator_name: ?string, price: ?int, preorder_price: ?int, preorder_deadline: ?string}
     */
    public static function fromText(?string $text, ?CarbonImmutable $postedAt = null): array
    {
        $text = (string) $text;
        $lines = array_values(array_filter(array_map(
            fn (string $line) => trim($line),
            preg_split('/\R/u', $text),
        ), fn (string $line) => preg_match('/\p{L}/u', $line)));

        $preorder = (bool) preg_match(self::PREORDER_WORDS, $text);
        $amounts = self::amounts($text);

        $price = null;
        $preorderPrice = null;

        if (count($amounts) >= 2) {
            // The usual post: the price and the lower pre-order price.
            [$preorderPrice, $price] = [min($amounts), max($amounts)];
        } elseif (count($amounts) === 1) {
            $preorder ? $preorderPrice = $amounts[0] : $price = $amounts[0];
        }

        return [
            'title' => self::title($lines),
            'author_name' => self::labelled($lines, 'muallif|muallifi|author|автор'),
            'translator_name' => self::labelled($lines, 'tarjimon|tarjimoni|tarjima\s+qildi|translator|translated\s+by|переводчик|перевод'),
            'price' => $price,
            'preorder_price' => $preorderPrice,
            'preorder_deadline' => $preorder ? self::deadline($text, $postedAt ?? CarbonImmutable::now()) : null,
        ];
    }

    /** «The title» from the first lines if quoted, else the first line with words in it. */
    private static function title(array $lines): string
    {
        foreach (array_slice($lines, 0, 3) as $line) {
            if (preg_match('/[«"“]([^»"”]{2,200})[»"”]/u', $line, $m)) {
                return trim($m[1]);
            }
        }

        $first = $lines[0] ?? '';
        $first = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}?!.)]+$/u', '', $first);

        return Str::limit($first !== '' ? $first : 'Untitled post', 200, '');
    }

    /** "✍️ Muallif: Name" and the like: the name after the label. */
    private static function labelled(array $lines, string $labels): ?string
    {
        foreach ($lines as $line) {
            $line = preg_replace('/^[^\p{L}]+/u', '', $line);

            if (preg_match('/^(?:'.$labels.')\s*[:\-–—]\s*(.+)$/iu', $line, $m)) {
                $name = trim(preg_replace('/[^\p{L}\p{N}.)]+$/u', '', $m[1]));

                return $name !== '' ? Str::limit($name, 200, '') : null;
            }
        }

        return null;
    }

    /**
     * Sums in so'm: "120 000 so'm", "95.000 сўм", "89000 som". Distinct, so
     * a price repeated twice is one price.
     *
     * @return list<int>
     */
    private static function amounts(string $text): array
    {
        preg_match_all("/(?<![\\d])(\\d{1,3}(?:[ \\x{00A0}.,]\\d{3})+|\\d{4,7})\\s*(?:so['ʻ‘’`]?m|сўм|сум|uzs)/iu", $text, $m);

        $amounts = array_map(fn (string $amount) => (int) preg_replace('/\D/', '', $amount), $m[1]);

        return array_values(array_unique(array_filter($amounts, fn (int $amount) => $amount >= 1000)));
    }

    /**
     * The pre-order's last day: "25.10", "25.10.2026", "25-oktabrgacha",
     * "до 25 октября". A day without a year is the next one to come.
     */
    private static function deadline(string $text, CarbonImmutable $postedAt): ?string
    {
        if (preg_match('/(?<!\d)(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{4}|\d{2}))?(?!\d)/u', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null];
        } elseif (preg_match('/(?<!\d)(\d{1,2})\s*-?\s*('.implode('|', array_keys(self::LATIN_MONTHS)).')(?:i?gacha|i?da|i?ga|dan)?(?!\p{L})/iu', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], self::LATIN_MONTHS[mb_strtolower($m[2])], null];
        } elseif (preg_match('/(?<!\d)(\d{1,2})\s*-?\s*('.implode('|', array_keys(self::CYRILLIC_MONTHS)).')[ьяеа]?(?!\p{L})/iu', $text, $m)) {
            [$day, $month, $year] = [(int) $m[1], self::CYRILLIC_MONTHS[mb_strtolower($m[2])], null];
        } else {
            return null;
        }

        if ($year !== null && $year < 100) {
            $year += 2000;
        }

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || ! checkdate($month, $day, $year ?? $postedAt->year)) {
            return null;
        }

        $date = CarbonImmutable::create($year ?? $postedAt->year, $month, $day);

        if ($year === null && $date->lt($postedAt->startOfDay())) {
            $date = $date->addYear();
        }

        return $date->toDateString();
    }
}
