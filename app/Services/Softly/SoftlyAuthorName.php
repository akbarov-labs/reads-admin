<?php

namespace App\Services\Softly;

use App\Support\UzbekText;

/**
 * The author a Softly library typed, as the catalogue should credit it.
 * Libraries write what is on the cover, and covers carry more than a name:
 *
 *  - words of respect after a scholar's name ("… rohimahulloh",
 *    "… rohmatullohi alayhi"), which are not part of it;
 *  - another name in brackets ("Hofiz ibn Kasir (Ibn Kasir)");
 *  - a title before it ("Shayx", "Imom"), which the catalogue may or may
 *    not have kept;
 *  - an editor ("Mhr: …"), who is not the author;
 *  - no person at all ("mualliflar jamoasi", a publisher's brand).
 *
 * parse() gives the name to credit and every spelling to look for first,
 * so a known author is found rather than made again.
 */
final class SoftlyAuthorName
{
    /** Written where the author goes, but not an author. Compared by UzbekText::key. */
    private const NOT_AN_AUTHOR = [
        'mualliflar jamoasi',
        'mualliflar guruhi',
        'mualliflar',
        'jamoa',
        "noma'lum",
        "muallif noma'lum",
        'muallifsiz',
        'usborne',
        'автор неизвестен',
        'коллектив авторов',
    ];

    /** An editor or translator credit, not the author. */
    private const NOT_THE_AUTHOR = "/^\s*(mhr|muharrir|tahrir|tarjimon|tarj)\s*[:.]/iu";

    /** Words of respect said after a name. Repeated: some names carry two. */
    private const AFTER_THE_NAME = "/(?:[\s,]+\(?|\s*\()\s*(r[oa]him[ao]h(?:u|umu)?ll[oa]h|r[oa]hmatull[oa]hi\s+alayh[ia]|r[oa]ziyall[oa]hu\s+anh[ua]|q\.\s?s\.?|r\.\s?a\.?)\s*\)?\s*$/iu";

    /** A title before the name. */
    private const BEFORE_THE_NAME = "/^(shayx|shaykh|imom|hazrati|mavlono|hofiz|domla|dr|prof)\b\.?\s+/iu";

    /** @return array{name: ?string, spellings: list<string>} */
    public static function parse(?string $raw): array
    {
        $text = UzbekText::clean($raw);

        if ($text === null || preg_match(self::NOT_THE_AUTHOR, $text) === 1) {
            return ['name' => null, 'spellings' => []];
        }

        $text = preg_replace('/^\s*(tuzuvchi|muallif)\s*[:.]\s*/iu', '', $text);

        do {
            $before = $text;
            $text = trim((string) preg_replace(self::AFTER_THE_NAME, '', $text));
        } while ($text !== $before && $text !== '');

        // "Hofiz ibn Kasir (Ibn Kasir)": the name, and another for it.
        $other = null;

        if (preg_match('/^(.+?)\s*\(([^()]+)\)$/u', $text, $parts) === 1) {
            $text = trim($parts[1]);
            $other = trim($parts[2]);
        }

        $notAuthors = array_map(fn (string $name) => UzbekText::key($name), self::NOT_AN_AUTHOR);

        if ($text === '' || in_array(UzbekText::key($text), $notAuthors, true) || UzbekText::key($text) === '') {
            return ['name' => null, 'spellings' => []];
        }

        // Without a title, only while two words are left: "Hofiz Xorazmiy"
        // is not "Xorazmiy", and one word alone could be anyone.
        $untitled = trim((string) preg_replace(self::BEFORE_THE_NAME, '', $text));
        $untitled = count(explode(' ', $untitled)) >= 2 ? $untitled : null;

        $spellings = collect([$text, $other, $untitled])
            ->filter(fn (?string $name) => $name !== null && mb_strlen($name) >= 3)
            ->unique(fn (string $name) => UzbekText::key($name))
            ->values()
            ->all();

        return ['name' => $text, 'spellings' => $spellings];
    }
}
