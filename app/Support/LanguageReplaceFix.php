<?php

namespace App\Support;

use App\Console\Commands\FixLanguageReplace;

/**
 * Undoes a find-and-replace that once ran over a batch of the catalogue
 * (about 3,000 books, ids 10,000 to 12,999, with their new authors and
 * publishers): every "uz", "ru" and "en", in any case and inside any word,
 * became "O'zbekcha", "Ruscha" and "Inglizcha". "Uzuklar hukmdori" became
 * "O'zbekchauklar hukmdori", "Stiven King" "StivInglizcha King", "Huzur"
 * "HO'zbekchaur". The code that did it is not in this repository; the
 * damage is in the data.
 *
 * Only a language name stuck to letters is undone: on its own ("Inglizcha-
 * o'zbekcha lug'at") it may be the real word. The case of the two letters
 * put back follows their neighbours, since the replacement lost it.
 *
 * @see FixLanguageReplace
 */
final class LanguageReplaceFix
{
    /** What was put in, and what it replaced. */
    private const TOKENS = ['Inglizcha' => 'en', 'Ruscha' => 'ru', "O'zbekcha" => 'uz'];

    /**
     * Endings a real "Inglizcha", "Ruscha" or "O'zbekcha" takes ("Inglizchadan
     * tarjima"): stuck to one of these, the word may be real, so the change
     * waits for a person.
     */
    private const SUFFIXES = ['da', 'dan', 'ga', 'ni', 'ning', 'si', 'sini', 'lar', 'larni', 'dagi', 'day', 'dek', 'mi', 'ku', 'gina'];

    /**
     * The text with every stuck language name undone, and whether that is
     * certain; null when there is nothing to undo.
     *
     * @return array{text: string, certain: bool}|null
     */
    public static function fix(?string $text): ?array
    {
        $result = null;

        // A word can hold two ("XInglizchaO'zbekcha"): the second is in the
        // first one's tail, so it takes another pass.
        for ($pass = 0; $pass < 3; $pass++) {
            $step = self::pass($result['text'] ?? $text);

            if ($step === null) {
                break;
            }

            $result = ['text' => $step['text'], 'certain' => $step['certain'] && ($result['certain'] ?? true)];
        }

        return $result;
    }

    /** @return array{text: string, certain: bool}|null */
    private static function pass(?string $text): ?array
    {
        if ($text === null || $text === '') {
            return null;
        }

        $pattern = '/(?<before>\p{L}?)(?<token>'.implode('|', array_map(fn (string $t) => preg_quote($t, '/'), array_keys(self::TOKENS))).')(?<after>\p{L}*)/u';
        $certain = true;
        $changed = false;

        $fixed = preg_replace_callback($pattern, function (array $m) use (&$certain, &$changed) {
            $before = $m['before'];
            $after = $m['after'];

            if ($before === '' && $after === '') {
                return $m[0];
            }

            // "Inglizchadan" at the start of a word reads as the real word.
            if ($before === '' && in_array(mb_strtolower($after), self::SUFFIXES, true)) {
                $certain = false;
            }

            $changed = true;

            return $before.self::restoreCase(self::TOKENS[$m['token']], $before, $after, $m[0]).$after;
        }, $text);

        return $changed ? ['text' => $fixed, 'certain' => $certain] : null;
    }

    /**
     * "uz", "Uz" or "UZ", by the word around it: upper case in a word in
     * capitals ("TO'zbekchaOQLARI"), a capital at the start of a word
     * ("O'zbekchauklar"), lower case inside one ("sargO'zbekchaashtlari").
     */
    private static function restoreCase(string $code, string $before, string $after, string $word): string
    {
        $others = $before.$after;

        if (mb_strlen($others) >= 2 && mb_strtoupper($others) === $others && mb_strtolower($others) !== $others) {
            return strtoupper($code);
        }

        return $before === '' ? ucfirst($code) : $code;
    }
}
