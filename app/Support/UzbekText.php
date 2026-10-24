<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Uzbek text normalisation for catalogue data that arrives in either
 * alphabet: Cyrillic → Latin transliteration (the 1995 official rules), one
 * apostrophe instead of the six that get typed for oʻ / gʻ / ʼ, and a
 * comparison key under which "Hilol nashr", "HILOL-Nashr" and "Ҳилол-нашр"
 * are the same publisher.
 */
class UzbekText
{
    private const VOWELS = 'аеёиоуўэюяы';

    /** @var array<string, string> Single-output letters. е and ц depend on position; see toLatin(). */
    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ё' => 'yo',
        'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'sh', 'ъ' => "'", 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'ў' => "o'", 'қ' => 'q', 'ғ' => "g'", 'ҳ' => 'h',
        'і' => 'i', 'ә' => 'a', 'ө' => 'o', 'ү' => 'u', 'ң' => 'ng',
    ];

    /**
     * Words that say what kind of company a publisher is rather than which
     * one. Dropped from the comparison key only — never from the stored name.
     */
    private const PUBLISHER_NOISE = [
        'nashr', 'nashri', 'nashriyot', 'nashriyoti', 'nashiryoti', 'nashriyotlari',
        'uyi', 'publishing', 'publisher', 'publishers', 'publish', 'books', 'book',
        'ltd', 'inc', 'llc',
    ];

    /** Misspellings seen in the Asaxiy export that no general rule catches. */
    private const PUBLISHER_ALIASES = [
        'maatbachi' => 'matbaachi',
        'yangiyulpoligraphservice' => 'yangiyulpoligrafservis',
    ];

    public static function hasCyrillic(?string $text): bool
    {
        return $text !== null && preg_match('/\p{Cyrillic}/u', $text) === 1;
    }

    /** Letters that exist only in Uzbek Cyrillic, i.e. proof the text is not Russian. */
    public static function hasUzbekCyrillic(?string $text): bool
    {
        return $text !== null && preg_match('/[ўқғҳЎҚҒҲ]/u', $text) === 1;
    }

    /**
     * o‘ oʻ o’ o` o´ → o' — the spelling the rest of the catalogue already
     * uses. Doubled inside a word ("mo''jiza", often o‘’ typed twice over)
     * it is still one apostrophe; a pair standing in for quote marks around
     * a word (''Kitob'') is left alone.
     */
    public static function normalizeApostrophes(string $text): string
    {
        $text = str_replace(['‘', '’', 'ʻ', 'ʼ', '`', '´', 'ʹ', '′'], "'", $text);

        return preg_replace("/(?<=\p{L})'{2,}(?=\p{L})/u", "'", $text);
    }

    public static function toLatin(string $text): string
    {
        $chars = mb_str_split($text);
        $out = '';

        foreach ($chars as $i => $char) {
            $lower = mb_strtolower($char);

            if ($lower === 'е') {
                $latin = self::startsSyllable($chars, $i) ? 'ye' : 'e';
            } elseif ($lower === 'ц') {
                $latin = $i > 0 && self::isVowel($chars[$i - 1]) ? 'ts' : 's';
            } elseif (isset(self::MAP[$lower])) {
                $latin = self::MAP[$lower];
            } else {
                $out .= $char;

                continue;
            }

            if ($lower !== $char && $latin !== '') {
                // Ш → "SH" inside an all-caps word, "Sh" everywhere else.
                $latin = self::isShouting($chars, $i) ? mb_strtoupper($latin) : ucfirst($latin);
            }

            $out .= $latin;
        }

        return self::normalizeApostrophes($out);
    }

    /** Trimmed, single-spaced, one apostrophe style. Empty becomes null. */
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // Left-to-right marks and friends ride along with copy-pasted ISBNs and names.
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', self::normalizeApostrophes($text)));

        return $text === '' ? null : $text;
    }

    /** Same person or book regardless of alphabet, case, spacing or punctuation. */
    public static function key(string $text): string
    {
        return implode('', self::words($text));
    }

    /**
     * What search compares, word by word: "Oʻtkan kunlar", "ЎТКАН КУНЛАР"
     * and "otkan kunlar" all become "otkan kunlar". Stored per row in
     * `search_key` (see SearchKeys) and applied to the query the same way,
     * because SQLite's LIKE folds case for ASCII only and has no notion of
     * the two alphabets. Null when nothing searchable is left.
     */
    public static function searchKey(?string ...$parts): ?string
    {
        $key = implode(' ', self::words(implode(' ', array_filter($parts))));

        return $key === '' ? null : $key;
    }

    /** Same publisher regardless of alphabet, case, punctuation, or "nashr" / "Books" suffixes. */
    public static function publisherKey(string $text): string
    {
        $words = self::words($text);
        $significant = array_values(array_diff($words, self::PUBLISHER_NOISE));
        $key = implode('', $significant ?: $words);

        return self::PUBLISHER_ALIASES[$key] ?? $key;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        // ASCII-folded too (ı → i, ş → s): MySQL's accent-insensitive
        // collation already treats those as equal, so the unique name
        // columns would reject a second row the key had kept apart.
        $latin = mb_strtolower(Str::ascii(str_replace("'", '', self::toLatin($text))));
        $latin = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $latin);

        return array_values(array_filter(explode(' ', $latin), fn (string $w) => $w !== ''));
    }

    /** Е is "ye" at the start of a word and after a vowel or a hard/soft sign. */
    private static function startsSyllable(array $chars, int $i): bool
    {
        if ($i === 0) {
            return true;
        }

        $previous = mb_strtolower($chars[$i - 1]);

        return self::isVowel($previous) || $previous === 'ъ' || $previous === 'ь'
            || preg_match('/\p{Cyrillic}/u', $previous) !== 1;
    }

    private static function isVowel(string $char): bool
    {
        return mb_strpos(self::VOWELS, mb_strtolower($char)) !== false;
    }

    private static function isShouting(array $chars, int $i): bool
    {
        foreach ([$i - 1, $i + 1] as $j) {
            $neighbour = $chars[$j] ?? '';

            if (preg_match('/\p{Lu}/u', $neighbour) === 1) {
                return true;
            }
        }

        return false;
    }
}
