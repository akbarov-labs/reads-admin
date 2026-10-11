<?php

namespace App\Support\Asaxiy;

use App\Support\UzbekText;
use DateTimeInterface;
use Generator;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the asaxiy.uz scrape (asaxiy_books.xlsx) and turns each row into a
 * clean record. Shared by the cover downloader and AsaxiyBooksSeeder so
 * both agree on which ISBN a row has.
 *
 * Uzbek-language rows are transliterated to Latin throughout; Russian,
 * English and other editions keep their own spelling.
 */
class AsaxiyCatalog
{
    /** Shelves of things that are never books. */
    private const NOT_BOOK_SHELVES = ['Pazllar', 'AMG'];

    /** Shelves of Asaxiy's own gift boxes and promotional bundles. */
    private const BUNDLE_SHELVES = ["Kitobli sovg'a to'plamlari", "Bestseller to'plamlar"];

    /** Asaxiy's name for "no publisher given". Public: the bot sync treats it the same way. */
    public const NO_PUBLISHER = ['boshqanashriyotlar', 'boshqa'];

    /** Particles allowed in lower case inside a person's name. */
    private const NAME_PARTICLES = ["o'g'li", "o'gli", 'qizi', 'ibn', 'al', 'ad', 'as', 'at', 'an', 'az', 'ar', 'de', 'da', 'di', 'van', 'von', 'fon', 'der', 'la', 'le', 'bin', 'binti', 'va', 'и', 'de la'];

    public function __construct(private readonly string $path) {}

    /**
     * @return Generator<int, array<string, ?string>> raw rows keyed by header, row number as key
     */
    public function rawRows(): Generator
    {
        $reader = new Reader;
        $reader->open($this->path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;

                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = array_map(self::stringify(...), $row->toArray());

                    if ($headers === null) {
                        $headers = $cells;

                        continue;
                    }

                    $record = [];
                    foreach ($headers as $i => $header) {
                        if ($header !== null) {
                            $record[$header] = $cells[$i] ?? null;
                        }
                    }

                    yield $number => $record;
                }

                break; // One sheet: "Books".
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function books(): Generator
    {
        foreach ($this->rawRows() as $number => $raw) {
            $book = self::normalize($raw);

            if ($book !== null) {
                yield $number => $book;
            }
        }
    }

    /**
     * @param  array<string, ?string>  $raw
     * @return array<string, mixed>|null null for a row with no usable title
     */
    public static function normalize(array $raw): ?array
    {
        $raw = array_map(UzbekText::clean(...), $raw);
        $languages = self::languages($raw['language'] ?? null, $raw);

        // Asaxiy tags a fair number of Russian books "O'zbekcha". Uzbek
        // Cyrillic has no ы or щ and uses ь only in loanwords, so a Cyrillic
        // title with those and none of ў/қ/ғ/ҳ is Russian, whatever the cell says.
        if ($languages === ["O'zbek"] && self::looksRussian($raw['title'] ?? null)) {
            $languages = ['Rus'];
        }
        $uzbek = in_array("O'zbek", $languages, true);
        $latin = fn (?string $text) => $text !== null && $uzbek ? UzbekText::toLatin($text) : $text;

        $author = $latin($raw['author'] ?? null);
        [$title, $author] = self::splitTitle($latin($raw['title'] ?? null), $author);

        if ($title === null) {
            return null;
        }

        $translators = self::names($latin($raw['translator'] ?? null));
        $title = self::stripListingNotes(self::stripFormatNotes($title), $raw['publisher'] ?? null);
        $authors = self::names($author);

        // The spelling in the author's own script, where the cell gave one:
        // an Uzbek edition typed in Cyrillic, or a foreign edition naming
        // "Абдулла Қодирий". Paired with `authors` by position.
        $rawAuthors = self::names($raw['author'] ?? null);
        $originalAuthors = count($rawAuthors) === count($authors)
            ? array_map(fn (string $name) => UzbekText::hasCyrillic($name) ? $name : null, $rawAuthors)
            : array_fill(0, count($authors), null);

        return [
            'isbn' => self::isbn($raw['isbn'] ?? null),
            'image_key' => self::isbn($raw['isbn'] ?? null) ?? ($raw['article'] ?? null),
            'title' => $title,
            'author' => $author,
            'authors' => $authors,
            'original_authors' => $originalAuthors,
            'translators' => $translators,
            'series' => self::series($title),
            'publisher' => self::publisher($raw['publisher'] ?? null, $uzbek),
            'description' => self::description($latin($raw['description'] ?? null)),
            'language' => $languages === [] ? null : implode(', ', array_map(fn ($l) => "{$l} tili", $languages)),
            'uzbek' => $uzbek,
            'script' => self::script($raw['script'] ?? null),
            'year' => self::year($raw['published_year'] ?? null),
            'pages' => self::smallInt($raw['pages'] ?? null),
            'cover_type' => $latin($raw['cover'] ?? null),
            'cover_material' => $raw['cover_material'] ?? null,
            'page_surface' => ($surface = $raw['page_surface'] ?? null) === null ? null : mb_convert_case($surface, MB_CASE_TITLE),
            'print_country' => ($country = $raw['country'] ?? null) === null || ctype_digit($country) ? null : $country,
            // "А4" is typed with a Cyrillic А surprisingly often.
            'paper_format' => ($format = $raw['paper_format'] ?? null) === null ? null : UzbekText::toLatin($format),
            'weight' => $raw['weight'] ?? null,
            'dimensions' => $raw['dimensions'] ?? null,
            'volume_count' => self::smallInt($raw['volume_count'] ?? null),
            'volume_part' => self::smallInt($raw['volume_part'] ?? null),
            'source_url' => $raw['url'] ?? null,
            'source_article' => $raw['article'] ?? null,
        ];
    }

    /**
     * The file name an edition's images are saved under: its ISBN, or
     * Asaxiy's article number for the few listings without a valid one —
     * `downloads/9789943231986.webp`, `downloads/T35050.webp`.
     *
     * @param  array<string, ?string>  $raw
     */
    public static function imageKey(array $raw): string
    {
        return self::isbn($raw['isbn'] ?? null) ?? (string) $raw['article'];
    }

    /**
     * Digits only, ISBN-10 or ISBN-13. A cell holding several ("978…, 978…"
     * or "978… / 978…") yields the first valid one; anything with the wrong
     * number of digits is rejected rather than guessed at.
     */
    /**
     * Whether a listing is something Asaxiy sells that is not a book:
     * puzzles and planners, and Asaxiy's own gift boxes and promotional
     * bundles ("Sovg'a uchun to'plam … (Shokolad, Sovgʻabop quti)",
     * "Beshtasi birda va super chegirma narxda!"). Told apart by the shelf
     * the product page's breadcrumb puts it on. A bundle that carries a real
     * ISBN is a publisher's product that happens to sit on that shelf, and
     * stays. Multi-volume sets from publishers ("Собрание сочинений в 8
     * томах") are books and are never on these shelves.
     *
     * @param  array<string, mixed>  $listing  a normalized listing, with the page's `breadcrumb`
     */
    public static function isNotABook(array $listing): bool
    {
        $shelf = UzbekText::key((string) ($listing['breadcrumb'] ?? ''));

        if (in_array($shelf, array_map(UzbekText::key(...), self::NOT_BOOK_SHELVES), true)) {
            return true;
        }

        if (in_array($shelf, array_map(UzbekText::key(...), self::BUNDLE_SHELVES), true)) {
            return $listing['isbn'] === null;
        }

        // Without a scraped page to say which shelf it is on, the gift boxes
        // are still recognisable by their title.
        return $listing['isbn'] === null
            && preg_match("/^sovg'a uchun to'plam\b/iu", UzbekText::normalizeApostrophes((string) $listing['title'])) === 1;
    }

    public static function isbn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (preg_split('/[,;\/|]| {2,}/', $value) as $candidate) {
            $digits = strtoupper(preg_replace('/[^0-9Xx]/', '', $candidate));

            if (preg_match('/^(97[89]\d{10}|\d{9}[\dX])$/', $digits) === 1) {
                return $digits;
            }
        }

        return null;
    }

    /**
     * Asaxiy titles read "Author: Title". The prefix comes off when it is the
     * row's author, or — when the author cell is empty — when it looks like
     * a person's name, which then becomes the author. "Dunyo daholari:",
     * "To'plam:" and other series labels fail the name test and stay put.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitTitle(?string $title, ?string $author): array
    {
        if ($title === null || ! preg_match('/^([^:]{2,80}?):\s+(.+)$/u', $title, $m)) {
            return [$title, $author];
        }

        [, $prefix, $rest] = $m;

        if ($author !== null) {
            $prefixKey = UzbekText::key($prefix);
            $authorKey = UzbekText::key($author);

            // "Dostoyevskiy: …" by "Fyodor Dostoyevskiy" still counts.
            $matches = $prefixKey !== '' && (str_contains($authorKey, $prefixKey) || str_contains($prefixKey, $authorKey));

            return $matches ? [$rest, $author] : [$title, $author];
        }

        return self::looksLikeNames($prefix) ? [$rest, $prefix] : [$title, null];
    }

    /**
     * A title that says which volume it is — "(Часть 1)", "2-kitob",
     * "3-qism", "Vol. 2" — names a series and a place in it. The series name
     * is the title with that marker taken off; the seeder only keeps series
     * that more than one book turns out to share.
     *
     * @return array{name: string, position: int}|null
     */
    public static function series(string $title): ?array
    {
        $patterns = [
            '/[\s,.:–—-]*[(\[]?\s*(?:Часть|Книга|Том|Qism|Kitob|Jild)\s*(\d{1,3})\s*[)\]]?\s*$/iu',
            '/[\s,.:–—-]*[(\[]?\s*(\d{1,3})\s*-?\s*(?:qism|kitob|jild|tom|часть|книга|том)\s*[)\]]?\s*$/iu',
            '/[\s,.:–—-]*[(\[]?\s*(?:Vol\.?|Volume|Book|Part)\s*(\d{1,3})\s*[)\]]?\s*$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $m, PREG_OFFSET_CAPTURE)) {
                $name = trim(substr($title, 0, $m[0][1]), " \t,.:;–—-(");

                return $name === '' ? null : ['name' => $name, 'position' => (int) $m[1][0]];
            }
        }

        return null;
    }

    /** "A, B va C" → ["A", "B", "C"] */
    public static function names(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|&|\s+va\s+|\s+и\s+|\s+and\s+)\s*/u', $value);

        return array_values(array_unique(array_filter(array_map(UzbekText::clean(...), $parts))));
    }

    private static function looksLikeNames(string $prefix): bool
    {
        foreach (self::names($prefix) as $name) {
            // "В.Драгунский", "H.G. Wells" — initials glued to the surname.
            $words = preg_split('/[\s.]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);

            if (count($words) < 2 || count($words) > 6 || preg_match('/[\d!?«»"“”]/u', $name)) {
                return false;
            }

            foreach ($words as $word) {
                $capitalised = preg_match('/^\p{Lu}/u', $word) === 1;

                if (! $capitalised && ! in_array(mb_strtolower($word), self::NAME_PARTICLES, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function looksRussian(?string $text): bool
    {
        return UzbekText::hasCyrillic($text)
            && ! UzbekText::hasUzbekCyrillic($text)
            && preg_match('/[ыщьЫЩЬ]/u', $text) === 1;
    }

    /** "(кирилл)", "(lotin alifbosida)", "(qattiq muqova)" describe the listing, not the book. */
    private static function stripFormatNotes(string $title): string
    {
        $title = preg_replace(
            '/\s*\((?:kirill|lotin|кирилл|лотин)(?:\s+alifbosida|\s+алифбосида|\s+yozuvida)?\)|\s*\((?:(?:qattiq|yumshoq|fleksibl|super)(?:\s+muqova)?|muqova)\)/iu',
            '',
            $title,
        );

        return trim($title);
    }

    /**
     * Asaxiy tells its own listings of one book apart with a trailing note —
     * "(2023)", "(qattiq)", "(Ziyo nashr)", the publisher's own name — and
     * sometimes leaves a stray "(" or "." at the end. None of it is the
     * book's title, and left in, it splits one book into several.
     */
    public static function stripListingNotes(string $title, ?string $publisher): string
    {
        $publisherKey = $publisher !== null ? UzbekText::publisherKey($publisher) : null;

        do {
            $before = $title;
            $title = rtrim($title, " \t.,;:(");

            if (preg_match('/^(.+?)\s*\(([^()]*)\)$/u', $title, $m)) {
                $note = trim($m[2]);
                $isListingNote = preg_match('/^\d{4}$/', $note)
                    || preg_match("/^(qattiq|yumshoq|fleksibl|sovg'abop|yangi nashr|lotin|kirill|lotin alifbosida|kirill alifbosida)$/iu", UzbekText::normalizeApostrophes($note))
                    || ($publisherKey !== null && $publisherKey !== '' && UzbekText::publisherKey($note) === $publisherKey)
                    // "Ziyo nashr", "Factor Press": a short name ending in
                    // a publisher word, not a subtitle that happens to use one.
                    || (str_word_count($note) <= 4 && preg_match('/\b(nashr|nashriyot|nashriyoti|press|books|publishing)$/iu', $note));

                if ($isListingNote) {
                    $title = $m[1];
                }
            }
        } while ($title !== $before && $title !== '');

        return $title;
    }

    private static function description(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return UzbekText::clean(preg_replace("/^(Mahsulot ta'rifi|Tavsif|Описание)\s*/u", '', $text));
    }

    private static function publisher(?string $name, bool $uzbekBook): ?string
    {
        if ($name === null || ctype_digit($name)) {
            return null;
        }

        if (in_array(UzbekText::publisherKey($name), self::NO_PUBLISHER, true)) {
            return null;
        }

        // Russian publishers (Эксмо, АСТ) keep their names. A Cyrillic name
        // on an Uzbek book, or one using ў/қ/ғ/ҳ, is an Uzbek publisher
        // written in the old alphabet.
        return $uzbekBook || UzbekText::hasUzbekCyrillic($name) ? UzbekText::toLatin($name) : $name;
    }

    /**
     * "O'zb/Rus", "Узб/Рус/Англ", "Uzb/English" → ["O'zbek", "Rus", "Ingliz"].
     * An empty cell is inferred from the text itself.
     *
     * @param  array<string, ?string>  $raw
     * @return list<string>
     */
    private static function languages(?string $value, array $raw): array
    {
        $map = [
            "o'zb" => "O'zbek", 'uzb' => "O'zbek", 'узб' => "O'zbek", 'ўзб' => "O'zbek",
            'rus' => 'Rus', 'рус' => 'Rus',
            'ingliz' => 'Ingliz', 'english' => 'Ingliz', 'англ' => 'Ingliz',
            'turk' => 'Turk', 'fransuz' => 'Fransuz', 'nemis' => 'Nemis',
            'arab' => 'Arab', 'араб' => 'Arab', 'qoraqalpoq' => 'Qoraqalpoq',
            'ispan' => 'Ispan', 'xitoy' => 'Xitoy', 'китай' => 'Xitoy', 'yapon' => 'Yapon',
        ];

        if ($value === null) {
            $sample = implode(' ', array_filter([$raw['title'] ?? null, $raw['publisher'] ?? null]));

            return match (true) {
                UzbekText::hasUzbekCyrillic($sample), preg_match("/[oOgG]'/u", $sample) === 1 => ["O'zbek"],
                UzbekText::hasCyrillic($sample) => ['Rus'],
                default => [],
            };
        }

        $found = [];
        foreach (preg_split('/[\s\/,]+/u', mb_strtolower($value)) as $token) {
            foreach ($map as $prefix => $language) {
                if (str_starts_with($token, $prefix)) {
                    $found[$language] = true;
                }
            }
        }

        return array_keys($found);
    }

    private static function script(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $scripts = [];
        if (preg_match('/kirill|кирилл/iu', $value)) {
            $scripts[] = 'Kirill';
        }
        if (preg_match('/lotin|лотин/iu', $value)) {
            $scripts[] = 'Lotin';
        }

        return $scripts === [] ? $value : implode(', ', $scripts);
    }

    /** Four digits in a plausible range; "20221", "256" and friends are typos, not years. */
    private static function year(?string $value): ?int
    {
        $year = $value !== null && preg_match('/^\d{4}$/', $value) ? (int) $value : null;

        return $year !== null && $year >= 1800 && $year <= (int) date('Y') + 1 ? $year : null;
    }

    private static function smallInt(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^\d+/', $value, $m)) {
            return null;
        }

        $number = (int) $m[0];

        return $number > 0 && $number <= 65535 ? $number : null;
    }

    /** Excel hands back ints, floats and dates; ISBNs typed as numbers must not become 9.78E+12. */
    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_float($value) => floor($value) === $value ? number_format($value, 0, '', '') : (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
